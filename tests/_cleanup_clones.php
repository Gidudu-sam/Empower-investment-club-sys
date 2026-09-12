<?php
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dbs = $pdo->query("SHOW DATABASES LIKE 'loansched_%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($dbs as $db) {
    $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
    echo "Dropped: {$db}\n";
}
echo "done\n";
