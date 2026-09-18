<?php
require __DIR__ . '/app/config/database.php';
require __DIR__ . '/core/Database.php';

$db = Database::getInstance()->getConnection();

echo "=== JOURNAL ENTRY JE00035 ===\n\n";

$je = $db->query("SELECT * FROM journal_entries WHERE entry_number = 'JE00035'")->fetch(PDO::FETCH_ASSOC);

if ($je) {
    echo "Entry Details:\n";
    echo json_encode($je, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    
    echo "Journal Lines for JE00035:\n";
    $lines = $db->query("
        SELECT jl.*, a.code, a.name 
        FROM journal_lines jl
        LEFT JOIN accounts a ON a.id = jl.account_id
        WHERE jl.journal_entry_id = {$je['id']}
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($lines as $line) {
        echo sprintf("  %s (%s): Dr %.2f, Cr %.2f\n",
            $line['code'],
            $line['name'],
            $line['debit'],
            $line['credit']
        );
    }
} else {
    echo "JE00035 NOT FOUND\n";
}
