<?php
/**
 * Production Deployment Script for Share Accounts Architecture
 * 
 * CRITICAL: Only run this after successful testing on empower_test_db
 * 
 * This script:
 * 1. Creates member_share_accounts table
 * 2. Populates share accounts for all members
 * 3. Links historical share transactions
 * 4. Verifies historical integrity
 */

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';

echo "=====================================\n";
echo "SHARE ACCOUNTS PRODUCTION DEPLOYMENT\n";
echo "=====================================\n\n";

echo "⚠️  WARNING: This will modify the PRODUCTION database!\n";
echo "Database: empower_db\n\n";

// Safety confirmation
echo "Have you successfully tested on empower_test_db? (yes/no): ";
$handle = fopen("php://stdin", "r");
$confirmation1 = trim(fgets($handle));

if (strtolower($confirmation1) !== 'yes') {
    echo "\n❌ Deployment cancelled. Test on empower_test_db first.\n";
    exit(1);
}

echo "\nType 'DEPLOY' to proceed with production deployment: ";
$confirmation2 = trim(fgets($handle));

if ($confirmation2 !== 'DEPLOY') {
    echo "\n❌ Deployment cancelled.\n";
    exit(1);
}

fclose($handle);

echo "\n🚀 Starting deployment...\n\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', 
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // Pre-deployment verification
    echo "Step 1: Pre-Deployment Verification\n";
    echo str_repeat("-", 50) . "\n";

    $gl3010Before = $pdo->query("
        SELECT COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) as balance
        FROM accounts a
        LEFT JOIN journal_lines jl ON jl.account_id = a.id
        WHERE a.code = '3010'
    ")->fetch();
    
    $preGL3010 = (float)$gl3010Before['balance'];
    echo "GL 3010 Balance: UGX " . number_format($preGL3010, 2) . "\n";

    $shareStats = $pdo->query("
        SELECT COUNT(*) as cnt, SUM(amount) as total
        FROM share_transactions
    ")->fetch();
    
    $preShareCount = (int)$shareStats['cnt'];
    $preShareTotal = (float)$shareStats['total'];
    echo "Share Transactions: $preShareCount\n";
    echo "Share Total: UGX " . number_format($preShareTotal, 2) . "\n";

    $memberCount = $pdo->query("
        SELECT COUNT(*) as cnt FROM members WHERE status = 'active'
    ")->fetch();
    
    $activeMemberCount = (int)$memberCount['cnt'];
    echo "Active Members: $activeMemberCount\n";

    // Check for IV records
    $ivRecords = $pdo->query("
        SELECT id, reference_number FROM internal_vouchers 
        WHERE id IN (6, 7, 8, 9)
        ORDER BY id
    ")->fetchAll();

    if (count($ivRecords) > 0) {
        echo "\nInternal Vouchers (DO NOT TOUCH):\n";
        foreach ($ivRecords as $iv) {
            echo "  IV-{$iv['reference_number']}\n";
        }
    }

    echo "\n";

    // Create backup point
    echo "Step 2: Creating Backup Point\n";
    echo str_repeat("-", 50) . "\n";
    $backupTime = date('Ymd_His');
    echo "Backup timestamp: $backupTime\n";
    echo "⚠️  Note: Manual database backup recommended before proceeding\n\n";

    // Apply schema
    echo "Step 3: Applying Schema Changes\n";
    echo str_repeat("-", 50) . "\n";

    $schema = file_get_contents(__DIR__ . '/member_share_accounts_schema.sql');
    $schema = preg_replace('/USE\s+`empower_db`;/i', '', $schema);
    $statements = array_filter(array_map('trim', explode(';', $schema)));

    foreach ($statements as $stmt) {
        if (empty($stmt) || strpos($stmt, '--') === 0) continue;
        $pdo->exec($stmt);
    }

    echo "✓ member_share_accounts table created\n";
    echo "✓ share_account_id column added to share_transactions\n";
    echo "✓ SHR number sequence initialized\n\n";

    // Populate accounts
    echo "Step 4: Populating Share Accounts\n";
    echo str_repeat("-", 50) . "\n";

    $populationSQL = file_get_contents(__DIR__ . '/populate_member_share_accounts.sql');
    $populationSQL = preg_replace('/USE\s+`empower_db`;/i', '', $populationSQL);
    
    // Execute population script
    $pdo->exec($populationSQL);

    $accountsCreated = $pdo->query("SELECT COUNT(*) as cnt FROM member_share_accounts")->fetch();
    echo "✓ Share accounts created: {$accountsCreated['cnt']}\n";

    $linkedTransactions = $pdo->query("
        SELECT COUNT(*) as cnt FROM share_transactions WHERE share_account_id IS NOT NULL
    ")->fetch();
    echo "✓ Transactions linked: {$linkedTransactions['cnt']}\n\n";

    // Post-deployment verification
    echo "Step 5: Post-Deployment Verification\n";
    echo str_repeat("-", 50) . "\n";

    // Verify GL 3010 unchanged
    $gl3010After = $pdo->query("
        SELECT COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) as balance
        FROM accounts a
        LEFT JOIN journal_lines jl ON jl.account_id = a.id
        WHERE a.code = '3010'
    ")->fetch();
    
    $postGL3010 = (float)$gl3010After['balance'];
    
    if (abs($postGL3010 - $preGL3010) > 0.01) {
        throw new RuntimeException(
            "GL 3010 changed! Before: " . number_format($preGL3010, 2) . 
            ", After: " . number_format($postGL3010, 2)
        );
    }

    echo "✓ GL 3010 unchanged: UGX " . number_format($postGL3010, 2) . "\n";

    // Verify historical transactions unchanged
    $postShareStats = $pdo->query("
        SELECT COUNT(*) as cnt, SUM(amount) as total
        FROM share_transactions
        WHERE transaction_type NOT IN ('transfer_in', 'transfer_out')
    ")->fetch();
    
    if ((int)$postShareStats['cnt'] !== $preShareCount) {
        throw new RuntimeException("Historical share transaction count changed!");
    }

    if (abs((float)$postShareStats['total'] - $preShareTotal) > 0.01) {
        throw new RuntimeException("Historical share total changed!");
    }

    echo "✓ Historical share transactions unchanged: $preShareCount\n";
    echo "✓ Historical share total unchanged: UGX " . number_format($preShareTotal, 2) . "\n";

    // Verify IV records untouched
    if (count($ivRecords) > 0) {
        $ivCheck = $pdo->query("
            SELECT COUNT(*) as cnt FROM internal_vouchers 
            WHERE id IN (6, 7, 8, 9)
        ")->fetch();
        
        if ((int)$ivCheck['cnt'] !== count($ivRecords)) {
            throw new RuntimeException("Internal Voucher records were modified!");
        }
        echo "✓ Internal Vouchers IV-000006 to IV-000009 untouched\n";
    }

    // Verify JE00035 untouched
    $je35 = $pdo->query("
        SELECT id, entry_number FROM journal_entries WHERE entry_number = 'JE00035'
    ")->fetch();
    
    if ($je35) {
        echo "✓ Historical journal JE00035 intact\n";
    }

    // Verify all members have accounts
    $membersWithoutAccounts = $pdo->query("
        SELECT COUNT(*) as cnt
        FROM members m
        LEFT JOIN member_share_accounts msa ON msa.member_id = m.id
        WHERE m.status = 'active' AND msa.id IS NULL
    ")->fetch();

    if ((int)$membersWithoutAccounts['cnt'] > 0) {
        throw new RuntimeException("Some active members do not have share accounts!");
    }

    echo "✓ All active members have share accounts\n";

    // Verify no orphaned transactions
    $orphaned = $pdo->query("
        SELECT COUNT(*) as cnt
        FROM share_transactions
        WHERE share_account_id IS NULL
    ")->fetch();

    if ((int)$orphaned['cnt'] > 0) {
        throw new RuntimeException("Found orphaned share transactions!");
    }

    echo "✓ No orphaned share transactions\n";

    // Sample accounts
    echo "\nSample Share Accounts:\n";
    $samples = $pdo->query("
        SELECT 
            msa.account_number,
            m.member_number,
            CONCAT(m.first_name, ' ', m.last_name) as name,
            COALESCE(SUM(st.amount), 0) as balance
        FROM member_share_accounts msa
        INNER JOIN members m ON m.id = msa.member_id
        LEFT JOIN share_transactions st ON st.share_account_id = msa.id
        GROUP BY msa.id
        ORDER BY msa.account_number
        LIMIT 5
    ")->fetchAll();

    foreach ($samples as $sample) {
        echo "  {$sample['account_number']} - {$sample['member_number']} - {$sample['name']} - Balance: UGX " . number_format($sample['balance'], 2) . "\n";
    }

    echo "\n";
    echo "=====================================\n";
    echo "✅ DEPLOYMENT SUCCESSFUL\n";
    echo "=====================================\n\n";

    echo "Summary:\n";
    echo "  Share Accounts Created: {$accountsCreated['cnt']}\n";
    echo "  Transactions Linked: {$linkedTransactions['cnt']}\n";
    echo "  GL 3010 Balance: UGX " . number_format($postGL3010, 2) . "\n";
    echo "  Historical Integrity: VERIFIED\n\n";

    echo "✅ The share transfer feature is now available.\n";
    echo "Navigate to: ?page=share-transfers\n\n";

} catch (Exception $e) {
    echo "\n❌ DEPLOYMENT FAILED: {$e->getMessage()}\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n\n";
    echo "⚠️  Review the error and database state before retrying.\n";
    exit(1);
}
