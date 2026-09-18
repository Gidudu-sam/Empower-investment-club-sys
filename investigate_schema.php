<?php
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';
require_once __DIR__ . '/core/Autoloader.php';

$db = Database::getInstance();

echo "=== CHECKING ACCOUNTS ===\n";
$accounts = $db->query("SELECT id, code, name, type FROM accounts WHERE code IN ('2020', '3010') ORDER BY code")->fetchAll();
foreach ($accounts as $acc) {
    echo "ID: {$acc['id']}, Code: {$acc['code']}, Name: {$acc['name']}, Type: {$acc['type']}\n";
}

echo "\n=== CHECKING IF member_share_accounts EXISTS ===\n";
$tables = $db->query("SHOW TABLES LIKE 'member_share_accounts'")->fetchAll();
if (count($tables) > 0) {
    echo "Table member_share_accounts EXISTS\n";
    $count = $db->query("SELECT COUNT(*) as cnt FROM member_share_accounts")->fetch();
    echo "Row count: {$count['cnt']}\n";
} else {
    echo "Table member_share_accounts DOES NOT EXIST\n";
}

echo "\n=== CHECKING share_transactions SCHEMA ===\n";
$cols = $db->query("DESCRIBE share_transactions")->fetchAll();
echo "Columns in share_transactions:\n";
foreach ($cols as $col) {
    echo "  {$col['Field']} ({$col['Type']}) {$col['Null']} {$col['Key']}\n";
}

echo "\n=== CHECKING MEMBERS COUNT ===\n";
$memberCount = $db->query("SELECT COUNT(*) as cnt, COUNT(DISTINCT member_number) as distinct_count FROM members WHERE status = 'active'")->fetch();
echo "Active members: {$memberCount['cnt']}, Distinct member_numbers: {$memberCount['distinct_count']}\n";

echo "\n=== CHECKING share_transactions STATS ===\n";
$shareStats = $db->query("
    SELECT 
        COUNT(*) as total_transactions,
        COUNT(DISTINCT member_id) as unique_members,
        SUM(amount) as total_amount
    FROM share_transactions
")->fetch();
echo "Share transactions: {$shareStats['total_transactions']}\n";
echo "Unique members: {$shareStats['unique_members']}\n";
echo "Total amount: UGX " . number_format($shareStats['total_amount'], 2) . "\n";

echo "\n=== CHECKING GL 3010 BALANCE ===\n";
$gl3010 = $db->query("
    SELECT 
        a.name,
        COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) as balance
    FROM accounts a
    LEFT JOIN journal_lines jl ON jl.account_id = a.id
    WHERE a.code = '3010'
    GROUP BY a.id, a.name
")->fetch();
if ($gl3010) {
    echo "Account: {$gl3010['name']}\n";
    echo "Balance: UGX " . number_format($gl3010['balance'], 2) . "\n";
}

echo "\n=== CHECKING journal_number_sequences FOR SHARE ACCOUNTS ===\n";
$seqs = $db->query("SELECT prefix, last_number FROM journal_number_sequences WHERE prefix LIKE 'SH%' OR prefix LIKE '%S'")->fetchAll();
foreach ($seqs as $seq) {
    echo "Prefix: {$seq['prefix']}, Last number: {$seq['last_number']}\n";
}

echo "\n=== CHECKING EXISTING SAVINGS ACCOUNT NUMBERING ===\n";
$savingsAccounts = $db->query("SELECT account_number FROM member_savings_accounts LIMIT 5")->fetchAll();
echo "Sample savings account numbers:\n";
foreach ($savingsAccounts as $sa) {
    echo "  {$sa['account_number']}\n";
}

echo "\n=== DONE ===\n";
