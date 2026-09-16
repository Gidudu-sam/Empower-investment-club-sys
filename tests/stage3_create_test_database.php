<?php
/**
 * Stage 3A — Create Disposable Test Database
 * 
 * Creates a disposable clone database for Stage 3A testing
 * 
 * SAFETY: This script does NOT touch production empower_db
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('TEST_DB_NAME', 'empower_stage3_test');
define('PRODUCTION_DB_NAME', 'empower_db');

echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo " Stage 3A — Create Disposable Test Database\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "Test Database: " . TEST_DB_NAME . "\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Connect to MySQL (no database selected yet)
try {
    $pdo = new PDO(
        "mysql:host=localhost;charset=utf8mb4",
        'root',
        ''
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "[✓] Connected to MySQL server\n\n";
} catch (PDOException $e) {
    die("[✗] FATAL: Cannot connect to MySQL: " . $e->getMessage() . "\n");
}

// Drop existing test database if it exists
echo "[*] Dropping existing test database (if exists)...\n";
try {
    $pdo->exec("DROP DATABASE IF EXISTS `" . TEST_DB_NAME . "`");
    echo "[✓] Existing test database dropped\n\n";
} catch (PDOException $e) {
    die("[✗] FATAL: Cannot drop test database: " . $e->getMessage() . "\n");
}

// Create fresh test database
echo "[*] Creating fresh test database...\n";
try {
    $pdo->exec("CREATE DATABASE `" . TEST_DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "[✓] Test database created: " . TEST_DB_NAME . "\n\n";
} catch (PDOException $e) {
    die("[✗] FATAL: Cannot create test database: " . $e->getMessage() . "\n");
}

// Switch to test database
$pdo->exec("USE `" . TEST_DB_NAME . "`");

// Create minimal schema required for testing
echo "[*] Creating minimal test schema...\n";

// Users table (minimal)
$pdo->exec("
    CREATE TABLE `users` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `role_id` INT UNSIGNED NOT NULL,
        `member_id` INT UNSIGNED NULL,
        `full_name` VARCHAR(150) NOT NULL,
        `email` VARCHAR(191) NOT NULL UNIQUE,
        `password_hash` VARCHAR(255) NOT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_role` (`role_id`),
        KEY `idx_member` (`member_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Roles table
$pdo->exec("
    CREATE TABLE `roles` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(50) NOT NULL UNIQUE,
        `label` VARCHAR(100) NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Seed officer roles
$pdo->exec("
    INSERT INTO `roles` (`id`, `name`, `label`) VALUES
    (1, 'admin', 'Administrator'),
    (2, 'treasurer', 'Treasurer'),
    (3, 'member', 'Member'),
    (7, 'chairman', 'Chairman / Team Leader'),
    (11, 'secretary', 'Secretary'),
    (12, 'vice_chairman', 'Vice Chairman')
");

// Create test users (one for each officer role + regular member)
$pdo->exec("
    INSERT INTO `users` (`id`, `role_id`, `member_id`, `full_name`, `email`, `password_hash`) VALUES
    (1, 7, 1, 'Test Chairman', 'chairman@test.local', '\$2y\$12\$dummyhash1'),
    (2, 12, 2, 'Test Vice Chairman', 'vice@test.local', '\$2y\$12\$dummyhash2'),
    (3, 11, 3, 'Test Secretary', 'secretary@test.local', '\$2y\$12\$dummyhash3'),
    (4, 2, 4, 'Test Treasurer', 'treasurer@test.local', '\$2y\$12\$dummyhash4'),
    (5, 3, 5, 'Test Member', 'member@test.local', '\$2y\$12\$dummyhash5'),
    (6, 1, NULL, 'Test Admin', 'admin@test.local', '\$2y\$12\$dummyhash6')
");

// Members table (minimal)
$pdo->exec("
    CREATE TABLE `members` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `member_number` VARCHAR(20) NOT NULL UNIQUE,
        `first_name` VARCHAR(100) NOT NULL,
        `last_name` VARCHAR(100) NOT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Seed test members
$pdo->exec("
    INSERT INTO `members` (`id`, `member_number`, `first_name`, `last_name`) VALUES
    (1, 'MEM001', 'Chairman', 'Officer'),
    (2, 'MEM002', 'Vice', 'Officer'),
    (3, 'MEM003', 'Secretary', 'Officer'),
    (4, 'MEM004', 'Treasurer', 'Officer'),
    (5, 'MEM005', 'Regular', 'Member'),
    (6, 'MEM006', 'Another', 'Member')
");

// Loans table (minimal - for testing)
$pdo->exec("
    CREATE TABLE `loans` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `loan_number` VARCHAR(20) NOT NULL UNIQUE,
        `member_id` INT UNSIGNED NOT NULL,
        `loan_amount` DECIMAL(15,2) NOT NULL,
        `amount` DECIMAL(15,2) NOT NULL,
        `status` ENUM('draft','pending_approval','approved','rejected','active','completed','defaulted') NOT NULL DEFAULT 'draft',
        `recorded_by` INT UNSIGNED NOT NULL,
        `submitted_at` TIMESTAMP NULL,
        `approved_by` INT UNSIGNED NULL,
        `approved_at` TIMESTAMP NULL,
        `rejected_by` INT UNSIGNED NULL,
        `rejected_at` TIMESTAMP NULL,
        `rejection_reason` TEXT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_member` (`member_id`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "[✓] Minimal schema created\n\n";

// Now apply V2.1 migration
echo "[*] Applying V2.1 approval schema migration...\n";
$migrationFile = dirname(__DIR__) . '/database/migrations/stage3_v21_approval_schema_migration.sql';

if (!file_exists($migrationFile)) {
    die("[✗] FATAL: Migration file not found: {$migrationFile}\n");
}

$migrationSQL = file_get_contents($migrationFile);

// Remove any USE statements for safety
$migrationSQL = preg_replace('/^USE\s+`?[^;`]+`?\s*;/mi', '', $migrationSQL);

// Execute migration (split by statements)
$statements = array_filter(
    array_map('trim', explode(';', $migrationSQL)),
    function($stmt) { return !empty($stmt) && !preg_match('/^--/', $stmt); }
);

$executedCount = 0;
foreach ($statements as $stmt) {
    if (empty(trim($stmt))) continue;
    try {
        $pdo->exec($stmt);
        $executedCount++;
    } catch (PDOException $e) {
        // Ignore harmless errors (e.g., "table already exists" with IF NOT EXISTS)
        if (strpos($e->getMessage(), 'already exists') === false &&
            strpos($e->getMessage(), 'Duplicate entry') === false) {
            echo "[!] Warning: " . $e->getMessage() . "\n";
        }
    }
}

echo "[✓] Migration applied ({$executedCount} statements executed)\n\n";

// Verify V2.1 tables exist
$tables = $pdo->query("SHOW TABLES LIKE 'approval_%'")->fetchAll(PDO::FETCH_COLUMN);
echo "[*] V2.1 tables created:\n";
foreach ($tables as $table) {
    echo "    - {$table}\n";
}
echo "\n";

// Final verification
$policyCount = $pdo->query("SELECT COUNT(*) FROM approval_policies WHERE transaction_type = 'loan'")->fetchColumn();
$tierCount = $pdo->query("SELECT COUNT(*) FROM approval_tiers WHERE policy_id = 1")->fetchColumn();
$slotCount = $pdo->query("SELECT COUNT(*) FROM approval_tier_slots")->fetchColumn();

echo "═══════════════════════════════════════════════════════════════════\n";
echo "TEST DATABASE READY\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "Database:       " . TEST_DB_NAME . "\n";
echo "Policies:       {$policyCount}\n";
echo "Tiers:          {$tierCount}\n";
echo "Slots:          {$slotCount}\n";
echo "Test Users:     6 (chairman, vice, secretary, treasurer, member, admin)\n";
echo "Test Members:   6\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "[✓] SUCCESS: Disposable test database is ready for Stage 3A testing\n\n";
echo "Next steps:\n";
echo "1. Run: php tests/stage3_approvalservice_loan_regression.php --confirmed-disposable\n";
echo "2. Review test results\n";
echo "3. After testing: DROP DATABASE " . TEST_DB_NAME . ";\n\n";
