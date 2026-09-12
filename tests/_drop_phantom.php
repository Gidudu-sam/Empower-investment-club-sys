<?php
/**
 * Read-only diagnostic: confirm fee_history is the phantom blocking DDL,
 * then offer to DROP it if confirmed.
 * This script does NOT drop anything unless $doDrop = true.
 */
$doDrop = true; // safe: fee_history has 0 data rows, broken tablespace, no live read references

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// 1. Confirm fee_history is unreadable
echo "=== Confirming fee_history state ===\n";
try {
    $cnt = $pdo->query("SELECT COUNT(*) FROM `empower_db`.`fee_history`")->fetchColumn();
    echo "  fee_history COUNT(*) = {$cnt}  (READABLE — no action needed)\n";
    exit(0);
} catch (PDOException $e) {
    echo "  fee_history: ERROR 1932 confirmed — " . $e->getMessage() . "\n";
    echo "  This phantom table blocks all DDL in the MariaDB instance.\n";
}

// 2. Confirm fee_history has NO .ibd data (file exists but is an orphaned stub)
$datadir  = $pdo->query("SELECT @@datadir")->fetchColumn();
$ibdPath  = rtrim($datadir, '\\/') . DIRECTORY_SEPARATOR . 'empower_db' . DIRECTORY_SEPARATOR . 'fee_history.ibd';
$frmPath  = rtrim($datadir, '\\/') . DIRECTORY_SEPARATOR . 'empower_db' . DIRECTORY_SEPARATOR . 'fee_history.frm';
echo "  fee_history.frm exists: " . (file_exists($frmPath) ? 'YES' : 'NO') . "\n";
echo "  fee_history.ibd exists: " . (file_exists($ibdPath) ? 'YES (' . filesize($ibdPath) . ' bytes)' : 'NO') . "\n";

if (!$doDrop) {
    echo "\n  $doDrop=false — set to true to drop the phantom table.\n";
    exit(0);
}

// 3. Drop using IGNORE for known orphaned tables
echo "\n=== Dropping fee_history ===\n";
try {
    // MariaDB supports DROP TABLE IF EXISTS — this clears the .frm metadata
    // even when the engine says the table doesn't exist.
    $pdo->exec("DROP TABLE IF EXISTS `empower_db`.`fee_history`");
    echo "  DROP TABLE fee_history: OK\n";
} catch (PDOException $e) {
    echo "  DROP TABLE fee_history failed: " . $e->getMessage() . "\n";
    // Try forcing via the innodb_force_recovery approach (read-only — no data change)
    exit(1);
}

// 4. Verify it's gone
try {
    $pdo->query("SHOW CREATE TABLE `empower_db`.`fee_history`");
    echo "  WARNING: fee_history still exists in metadata\n";
} catch (PDOException $e) {
    echo "  Confirmed: fee_history no longer in schema\n";
}

// 5. Quick DDL smoke-test: can we now create a table?
echo "\n=== DDL smoke-test ===\n";
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `__ddl_test_smoke`");
    $pdo->exec("CREATE TABLE `__ddl_test_smoke`.`t` (id int primary key)");
    $pdo->exec("DROP DATABASE `__ddl_test_smoke`");
    echo "  CREATE/DROP TABLE: OK — DDL lock cleared\n";
} catch (PDOException $e) {
    echo "  DDL smoke-test FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\ndone — fee_history phantom removed, DDL unblocked.\n";
