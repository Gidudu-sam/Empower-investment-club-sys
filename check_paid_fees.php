<?php
require_once 'app/config/database.php';

$db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);

echo "Summary of Fees by Status:\n";
echo str_repeat('=', 80) . "\n\n";

$stmt = $db->query("
    SELECT 
        f.fee_name,
        mf.status,
        COUNT(*) as count,
        SUM(mf.amount) as total_amount
    FROM member_fees mf
    JOIN fees f ON mf.fee_id = f.id
    GROUP BY f.fee_name, mf.status
    ORDER BY f.fee_name, mf.status
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['fee_name']} - {$row['status']}: {$row['count']} fees, UGX " . number_format($row['total_amount'], 0) . "\n";
}

echo "\n\nPaid Fees Detail:\n";
echo str_repeat('=', 80) . "\n\n";

$stmt2 = $db->query("
    SELECT 
        mf.id,
        f.fee_name,
        mf.amount,
        mf.payment_method,
        mf.paid_date,
        mf.journal_entry_id
    FROM member_fees mf
    JOIN fees f ON mf.fee_id = f.id
    WHERE mf.status = 'paid'
    ORDER BY mf.paid_date DESC
");

$count = 0;
while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    $count++;
    echo "{$row['id']}. {$row['fee_name']} - UGX " . number_format($row['amount'], 0);
    echo " - {$row['payment_method']} - {$row['paid_date']}";
    if ($row['journal_entry_id']) {
        echo " - JE#{$row['journal_entry_id']}";
    }
    echo "\n";
}

if ($count === 0) {
    echo "⚠️  NO PAID FEES FOUND! All fees are pending.\n";
}

echo "\n\nTotal Revenue from Paid Fees:\n";
echo str_repeat('=', 80) . "\n\n";

$stmt3 = $db->query("
    SELECT 
        f.fee_name,
        SUM(mf.amount) as total_amount,
        COUNT(*) as count
    FROM member_fees mf
    JOIN fees f ON mf.fee_id = f.id
    WHERE mf.status = 'paid'
    GROUP BY f.fee_name
");

while ($row = $stmt3->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['fee_name']}: {$row['count']} payments = UGX " . number_format($row['total_amount'], 0) . "\n";
}
