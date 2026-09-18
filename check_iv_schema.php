<?php
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';
require_once __DIR__ . '/core/Autoloader.php';

$db = Database::getInstance();

echo "=== INTERNAL VOUCHERS SCHEMA ===\n";
$cols = $db->query("DESCRIBE internal_vouchers")->fetchAll();
foreach ($cols as $col) {
    echo sprintf("%-25s %-30s %s\n", $col['Field'], $col['Type'], $col['Null']);
}
