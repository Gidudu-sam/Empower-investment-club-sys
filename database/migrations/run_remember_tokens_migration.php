<?php
/**
 * Migration Runner: Create remember_tokens table
 * Run this file directly: php database/migrations/run_remember_tokens_migration.php
 */

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../core/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Running migration: create_remember_tokens_table\n";
    echo "=====================================\n\n";
    
    // Read migration SQL
    $sql = file_get_contents(__DIR__ . '/create_remember_tokens_table.sql');
    
    // Execute table creation (everything before DELIMITER)
    $parts = preg_split('/-- Cleanup procedure.*DELIMITER/s', $sql);
    if (!empty($parts[0])) {
        $db->exec(trim($parts[0]));
        echo "✓ Table 'remember_tokens' created successfully\n";
    }
    
    // Execute stored procedure separately
    $procSQL = "
CREATE PROCEDURE IF NOT EXISTS cleanup_expired_remember_tokens()
BEGIN
    DELETE FROM remember_tokens WHERE expires_at < NOW();
END";
    
    $db->exec($procSQL);
    echo "✓ Stored procedure 'cleanup_expired_remember_tokens' created successfully\n";
    
    echo "\n✅ Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
