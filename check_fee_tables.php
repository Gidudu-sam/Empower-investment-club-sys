<?php
require_once 'app/config/database.php';

$db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);

echo "Fee-related tables:\n";
echo str_repeat('=', 80) . "\n";

$stmt = $db->query("SHOW TABLES");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    if (stripos($row[0], 'fee') !== false) {
        echo $row[0] . "\n";
    }
}

echo "\n\nChecking member_fees table:\n";
echo str_repeat('=', 80) . "\n";
$stmt2 = $db->query("DESCRIBE member_fees");
while ($col = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$col['Field']} - {$col['Type']}\n";
}

echo "\n\nChecking fees table:\n";
echo str_repeat('=', 80) . "\n";
$stmt3 = $db->query("DESCRIBE fees");
while ($col = $stmt3->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$col['Field']} - {$col['Type']}\n";
}

echo "\n\nFees data:\n";
echo str_repeat('=', 80) . "\n";

$stmt4 = $db->query("SELECT * FROM fees ORDER BY id");

while ($row = $stmt4->fetch(PDO::FETCH_ASSOC)) {
    echo "\nFee ID: {$row['id']}\n";
    echo "  Name: {$row['fee_name']}\n";
    echo "  Type: {$row['fee_type']}\n";
    echo "  Amount: UGX " . number_format($row['amount'], 0) . "\n";
    echo "  Frequency: {$row['frequency']}\n";
    echo "  Active: " . ($row['is_active'] ? 'Yes' : 'No') . "\n";
}

echo "\n\nRecent member_fees with payment status:\n";
echo str_repeat('=', 80) . "\n";

$stmt5 = $db->query("
    SELECT 
        mf.id,
        mf.member_id,
        f.fee_name,
        mf.amount,
        mf.status,
        mf.journal_entry_id,
        mf.created_at
    FROM member_fees mf
    JOIN fees f ON mf.fee_id = f.id
    ORDER BY mf.id DESC
    LIMIT 10
");

while ($row = $stmt5->fetch(PDO::FETCH_ASSOC)) {
    echo "Fee ID: {$row['id']} - {$row['fee_name']} - UGX " . number_format($row['amount'], 0) . " - Status: {$row['status']}";
    if ($row['journal_entry_id']) {
        echo " - JE: {$row['journal_entry_id']}";
    } else {
        echo " - ⚠️ NO JOURNAL ENTRY";
    }
    echo " - {$row['created_at']}\n";
}
