<?php
set_time_limit(15);
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

// Check what FK locks are currently held
echo "=== INNODB LOCK WAITS ===\n";
try {
    $waits = $pdo->query("SELECT * FROM information_schema.INNODB_LOCK_WAITS LIMIT 10")->fetchAll();
    echo "Lock waits: " . count($waits) . "\n";
} catch (Exception $e) { echo "n/a: " . $e->getMessage() . "\n"; }

// Check currently running processes
echo "\n=== PROCESSLIST ===\n";
$procs = $pdo->query("SHOW FULL PROCESSLIST")->fetchAll();
foreach ($procs as $p) {
    echo "  id={$p['Id']} db={$p['db']} state={$p['State']} time={$p['Time']} info=" . substr($p['Info']??'',0,80) . "\n";
}

// Check loan_weekly_interest CREATE TABLE
echo "\n=== loan_weekly_interest CREATE TABLE ===\n";
$r = $pdo->query("SHOW CREATE TABLE `empower_db`.`loan_weekly_interest`")->fetch();
echo $r['Create Table'] . "\n";
