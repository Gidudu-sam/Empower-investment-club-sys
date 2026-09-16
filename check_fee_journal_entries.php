<?php
require_once 'app/config/database.php';

$db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);

echo "Journal Entries for Fees:\n";
echo str_repeat('=', 80) . "\n\n";

$stmt = $db->query("
    SELECT 
        je.id AS je_id,
        je.description,
        je.created_at,
        jel.account_id,
        a.account_code,
        a.account_name,
        jel.debit_amount,
        jel.credit_amount
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    JOIN accounts a ON jel.account_id = a.id
    WHERE je.description LIKE '%fee%'
       OR je.description LIKE '%subscription%'
       OR je.description LIKE '%processing%'
    ORDER BY je.id DESC, jel.id
    LIMIT 40
");

$currentJE = null;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($currentJE !== $row['je_id']) {
        if ($currentJE !== null) echo "\n";
        echo "JE#{$row['je_id']}: {$row['description']} ({$row['created_at']})\n";
        $currentJE = $row['je_id'];
    }
    
    $debit = $row['debit_amount'] > 0 ? 'DR ' . number_format($row['debit_amount'], 0) : '';
    $credit = $row['credit_amount'] > 0 ? 'CR ' . number_format($row['credit_amount'], 0) : '';
    echo "  [{$row['account_code']}] {$row['account_name']} - {$debit}{$credit}\n";
}
