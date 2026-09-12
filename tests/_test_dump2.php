<?php
// Test: enumerate unreadable tables and dump with --ignore-table
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

$unreadable = $pdo->query(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA='empower_db' AND (ENGINE IS NULL OR TABLE_ROWS IS NULL)"
)->fetchAll(PDO::FETCH_COLUMN);

echo "Unreadable table count: " . count($unreadable) . "\n";
foreach ($unreadable as $t) {
    echo "  [" . $t . "]\n";
}

// Build argv
$mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
$argv = [
    $mysqldump,
    '--no-defaults',
    '-h', '127.0.0.1', '-P', '3306', '-u', 'root',
    '--no-data', '--skip-lock-tables', '--single-transaction',
];
foreach ($unreadable as $t) {
    $argv[] = '--ignore-table=empower_db.' . $t;
}
$argv[] = 'empower_db';

echo "\nfirst --ignore-table arg: [" . ($argv[12]??'?') . "]\n";
echo "last arg: [" . end($argv) . "]\n";

set_time_limit(20);
$desc = [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']];
$proc = proc_open($argv, $desc, $pipes);
$out  = stream_get_contents($pipes[1]);
$err  = stream_get_contents($pipes[2]);
fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
proc_close($proc);

echo "\nStdout bytes: " . strlen($out) . "\n";
echo "Stderr first 300: " . substr($err, 0, 300) . "\n";
