#!/usr/bin/env php
<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=empower_db', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    $tables = $pdo->query("SHOW TABLES LIKE 'approval%'")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Approval tables in empower_db:\n";
    if (count($tables) > 0) {
        foreach ($tables as $table) {
            echo "  - {$table}\n";
        }
    } else {
        echo "  NONE (V2.1 schema not yet migrated to production)\n";
    }
    
    // Check loans table for legacy approval fields
    echo "\nLegacy approval fields in loans table:\n";
    $columns = $pdo->query("SHOW COLUMNS FROM loans")->fetchAll(PDO::FETCH_ASSOC);
    $approvalCols = array_filter($columns, fn($c) => preg_match('/approv|reject/i', $c['Field']));
    
    foreach ($approvalCols as $col) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
