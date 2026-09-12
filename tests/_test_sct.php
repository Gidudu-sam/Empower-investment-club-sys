<?php
set_time_limit(5);
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$tests = ['roles','accounts','financial_years','fees','loan_types','loans','loan_installments'];
foreach ($tests as $t) {
    $start = microtime(true);
    try {
        $r = $pdo->query("SHOW CREATE TABLE `empower_db`.`{$t}`")->fetch();
        echo "OK " . round((microtime(true)-$start)*1000,1) . "ms: {$t}\n";
    } catch (Throwable $e) {
        echo "ERR " . round((microtime(true)-$start)*1000,1) . "ms: {$t}: " . $e->getMessage() . "\n";
    }
}
echo "done\n";
