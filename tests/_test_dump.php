<?php
// Quick test of proc_open with mysqldump
$mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
$argv_test = [
    $mysqldump,
    '--no-defaults',
    '-h', '127.0.0.1',
    '-P', '3306',
    '-u', 'root',
    '--no-data',
    '--skip-lock-tables',
    'empower_db',
    'loans',
];
echo "argv: " . json_encode($argv_test) . "\n";

$desc = [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']];
$proc = proc_open($argv_test, $desc, $pipes);
$out  = stream_get_contents($pipes[1]);
$err  = stream_get_contents($pipes[2]);
fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
$ret  = proc_close($proc);
echo "Return code: $ret\n";
echo "Stdout bytes: " . strlen($out) . "\n";
echo "Stderr: " . substr($err, 0, 200) . "\n";
echo "First 300 chars of output: " . substr($out, 0, 300) . "\n";
