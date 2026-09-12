<?php
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
echo "=== PROCESSLIST ===\n";
foreach ($pdo->query("SHOW FULL PROCESSLIST")->fetchAll() as $p) {
    echo "  id={$p['Id']} db={$p['db']} state=[{$p['State']}] time={$p['Time']} cmd={$p['Command']} info=" . substr($p['Info']??'',0,100) . "\n";
}
echo "\n=== INNODB STATUS (first 2000 chars) ===\n";
$r = $pdo->query("SHOW ENGINE INNODB STATUS")->fetch();
echo substr($r['Status']??'', 0, 2000) . "\n";
echo "\n=== loansched_ databases ===\n";
foreach ($pdo->query("SHOW DATABASES LIKE 'loansched_%'")->fetchAll(PDO::FETCH_COLUMN) as $db) {
    echo "  $db\n";
}
