<?php
set_time_limit(5);
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
echo "SHOW CREATE TABLE financial_years...\n";
$r = $pdo->query("SHOW CREATE TABLE `empower_db`.`financial_years`")->fetch();
echo substr($r['Create Table']??'', 0, 500) . "\n";
echo "SHOW CREATE TABLE accounting_periods...\n";
$r2 = $pdo->query("SHOW CREATE TABLE `empower_db`.`accounting_periods`")->fetch();
echo substr($r2['Create Table']??'', 0, 500) . "\n";
echo "done\n";
