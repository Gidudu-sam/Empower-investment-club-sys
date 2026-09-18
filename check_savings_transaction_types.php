<?php
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';
require_once __DIR__ . '/core/Autoloader.php';

$db = Database::getInstance();

echo "=== SAVINGS TRANSACTION_TYPE VALUES ===\n";
$cols = $db->query("DESCRIBE savings")->fetchAll();
foreach ($cols as $col) {
    if ($col['Field'] == 'transaction_type') {
        echo $col['Type'] . "\n";
    }
}
