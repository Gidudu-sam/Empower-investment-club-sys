<?php
set_time_limit(5);
try {
    $p = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
    echo "OK: " . $p->query('SELECT VERSION()')->fetchColumn() . "\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}
