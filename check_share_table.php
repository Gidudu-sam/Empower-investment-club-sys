<?php
require __DIR__ . '/app/config/database.php';
require __DIR__ . '/core/Database.php';

$db = Database::getInstance()->getConnection();

echo "=== share_transactions TABLE SCHEMA ===\n";
$cols = $db->query('DESCRIBE share_transactions')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    echo "{$col['Field']} - {$col['Type']}\n";
}

echo "\n=== share_transactions DATA ===\n";
$data = $db->query('SELECT * FROM share_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
echo "Total records: " . count($data) . "\n\n";

foreach ($data as $row) {
    echo "ID {$row['id']}: ";
    echo json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
}
