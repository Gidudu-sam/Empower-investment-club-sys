<?php
set_time_limit(10);
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Kill all connections to loansched_ databases
$procs = $pdo->query("SHOW FULL PROCESSLIST")->fetchAll(PDO::FETCH_ASSOC);
$killed = 0;
foreach ($procs as $p) {
    if (str_starts_with((string)($p['db'] ?? ''), 'loansched_')) {
        try {
            $pdo->exec("KILL " . (int)$p['Id']);
            echo "KILLED connection id={$p['Id']} db={$p['db']}\n";
            $killed++;
        } catch (PDOException $e) {
            echo "  could not kill {$p['Id']}: " . $e->getMessage() . "\n";
        }
    }
}
echo "Killed {$killed} connections.\n";

// Now drop stale clone databases
$dbs = $pdo->query("SHOW DATABASES LIKE 'loansched_%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($dbs as $db) {
    $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
    echo "Dropped: {$db}\n";
}
echo "done\n";
