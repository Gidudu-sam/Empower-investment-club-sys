<?php
/**
 * Setup Test Database for Share Accounts Migration Testing
 * 
 * This script creates a disposable clone of the production database
 * for safe testing before production deployment.
 */

echo "=====================================\n";
echo "TEST DATABASE SETUP\n";
echo "=====================================\n\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', 'localhost', '3306'),
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    echo "Step 1: Drop existing test database (if exists)...\n";
    $pdo->exec("DROP DATABASE IF EXISTS empower_test_db");
    echo "✓ Done\n\n";

    echo "Step 2: Create new test database...\n";
    $pdo->exec("CREATE DATABASE empower_test_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✓ Created empower_test_db\n\n";

    echo "Step 3: Clone production database structure and data...\n";
    
    // Get all tables from production
    $pdo->exec("USE empower_db");
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Found " . count($tables) . " tables to clone\n";

    foreach ($tables as $table) {
        echo "  Cloning table: $table...";
        
        // Get CREATE TABLE statement
        $createStmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        $createSQL = $createStmt['Create Table'];
        
        // Create table in test database
        $pdo->exec("USE empower_test_db");
        $pdo->exec($createSQL);
        
        // Copy data
        $pdo->exec("INSERT INTO empower_test_db.`$table` SELECT * FROM empower_db.`$table`");
        
        echo " ✓\n";
    }

    echo "\nStep 4: Verify test database...\n";
    $pdo->exec("USE empower_test_db");
    
    // Count key records
    $memberCount = $pdo->query("SELECT COUNT(*) as cnt FROM members")->fetch();
    $shareCount = $pdo->query("SELECT COUNT(*) as cnt FROM share_transactions")->fetch();
    $jeCount = $pdo->query("SELECT COUNT(*) as cnt FROM journal_entries")->fetch();
    
    echo "  Members: {$memberCount['cnt']}\n";
    echo "  Share Transactions: {$shareCount['cnt']}\n";
    echo "  Journal Entries: {$jeCount['cnt']}\n";

    // Check GL 3010
    $gl3010 = $pdo->query("
        SELECT COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) as balance
        FROM accounts a
        LEFT JOIN journal_lines jl ON jl.account_id = a.id
        WHERE a.code = '3010'
    ")->fetch();
    
    echo "  GL 3010 Balance: UGX " . number_format($gl3010['balance'], 2) . "\n";

    echo "\n✅ TEST DATABASE READY\n";
    echo "Database: empower_test_db\n";
    echo "Status: Exact clone of production\n\n";
    echo "You can now run: php tests/test_share_accounts_migration.php\n";

} catch (PDOException $e) {
    echo "\n❌ ERROR: {$e->getMessage()}\n";
    exit(1);
}
