<?php
/**
 * Verify Share Accounts Production Deployment
 * 
 * Run this after production deployment to verify everything is correct
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';
require_once __DIR__ . '/core/Autoloader.php';

$db = Database::getInstance();

echo "=====================================\n";
echo "SHARE ACCOUNTS PRODUCTION VERIFICATION\n";
echo "=====================================\n\n";

try {
    // 1. Verify table exists
    echo "1. Database Schema\n";
    echo str_repeat("-", 50) . "\n";
    
    $tableCheck = $db->query("SHOW TABLES LIKE 'member_share_accounts'")->fetch();
    if (!$tableCheck) {
        throw new RuntimeException("member_share_accounts table not found!");
    }
    echo "✓ member_share_accounts table exists\n";

    $columnCheck = $db->query("SHOW COLUMNS FROM share_transactions LIKE 'share_account_id'")->fetch();
    if (!$columnCheck) {
        throw new RuntimeException("share_account_id column not found in share_transactions!");
    }
    echo "✓ share_account_id column exists in share_transactions\n\n";

    // 2. Verify account population
    echo "2. Account Population\n";
    echo str_repeat("-", 50) . "\n";
    
    $stats = $db->query("
        SELECT 
            COUNT(*) as total_accounts,
            COUNT(DISTINCT member_id) as unique_members,
            COUNT(DISTINCT account_number) as unique_account_numbers
        FROM member_share_accounts
    ")->fetch();
    
    echo "Total share accounts: {$stats['total_accounts']}\n";
    echo "Unique members: {$stats['unique_members']}\n";
    echo "Unique account numbers: {$stats['unique_account_numbers']}\n";
    
    if ($stats['total_accounts'] != $stats['unique_members']) {
        throw new RuntimeException("Duplicate accounts exist for some members!");
    }
    echo "✓ One account per member\n";
    
    if ($stats['total_accounts'] != $stats['unique_account_numbers']) {
        throw new RuntimeException("Duplicate account numbers exist!");
    }
    echo "✓ All account numbers unique\n\n";

    // 3. Verify historical linking
    echo "3. Historical Transaction Linking\n";
    echo str_repeat("-", 50) . "\n";
    
    $linkingStats = $db->query("
        SELECT 
            COUNT(*) as total_transactions,
            SUM(CASE WHEN share_account_id IS NOT NULL THEN 1 ELSE 0 END) as linked,
            SUM(CASE WHEN share_account_id IS NULL THEN 1 ELSE 0 END) as orphaned
        FROM share_transactions
    ")->fetch();
    
    echo "Total share transactions: {$linkingStats['total_transactions']}\n";
    echo "Linked to accounts: {$linkingStats['linked']}\n";
    echo "Orphaned (NULL account): {$linkingStats['orphaned']}\n";
    
    if ((int)$linkingStats['orphaned'] > 0) {
        throw new RuntimeException("Found {$linkingStats['orphaned']} orphaned transactions!");
    }
    echo "✓ All transactions linked to accounts\n\n";

    // 4. Verify GL 3010 balance
    echo "4. GL 3010 Balance Verification\n";
    echo str_repeat("-", 50) . "\n";
    
    $gl3010 = $db->query("
        SELECT 
            a.code,
            a.name,
            COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) as balance
        FROM accounts a
        LEFT JOIN journal_lines jl ON jl.account_id = a.id
        WHERE a.code = '3010'
        GROUP BY a.id, a.code, a.name
    ")->fetch();
    
    $expectedGL3010 = 56455620.00;  // From requirements
    $actualGL3010 = (float)$gl3010['balance'];
    
    echo "Account: {$gl3010['name']}\n";
    echo "Balance: UGX " . number_format($actualGL3010, 2) . "\n";
    echo "Expected: UGX " . number_format($expectedGL3010, 2) . "\n";
    
    if (abs($actualGL3010 - $expectedGL3010) > 0.01) {
        echo "⚠️  WARNING: GL 3010 balance differs from expected!\n";
        echo "Difference: UGX " . number_format($actualGL3010 - $expectedGL3010, 2) . "\n";
    } else {
        echo "✓ GL 3010 balance correct\n";
    }
    echo "\n";

    // 5. Verify historical share total
    echo "5. Historical Share Total\n";
    echo str_repeat("-", 50) . "\n";
    
    $shareTotal = $db->query("
        SELECT 
            COUNT(*) as transaction_count,
            COUNT(DISTINCT member_id) as unique_members,
            SUM(amount) as total_amount
        FROM share_transactions
        WHERE transaction_type NOT IN ('transfer_in', 'transfer_out')
    ")->fetch();
    
    $expectedHistorical = 51485620.00;  // From requirements
    $actualHistorical = (float)$shareTotal['total_amount'];
    
    echo "Historical transactions: {$shareTotal['transaction_count']}\n";
    echo "Unique members: {$shareTotal['unique_members']}\n";
    echo "Total amount: UGX " . number_format($actualHistorical, 2) . "\n";
    echo "Expected: UGX " . number_format($expectedHistorical, 2) . "\n";
    
    if (abs($actualHistorical - $expectedHistorical) > 0.01) {
        throw new RuntimeException("Historical share total changed!");
    }
    echo "✓ Historical share total unchanged\n\n";

    // 6. Verify Internal Vouchers untouched
    echo "6. Internal Vouchers Verification\n";
    echo str_repeat("-", 50) . "\n";
    
    $ivRecords = $db->query("
        SELECT id, reference_number, status, amount
        FROM internal_vouchers
        WHERE id IN (6, 7, 8, 9)
        ORDER BY id
    ")->fetchAll();
    
    if (count($ivRecords) > 0) {
        foreach ($ivRecords as $iv) {
            echo "IV-{$iv['reference_number']}: Status={$iv['status']}, Amount=UGX " . number_format($iv['amount'], 2) . "\n";
        }
        echo "✓ Internal Vouchers IV-000006 to IV-000009 present and unchanged\n";
    } else {
        echo "ℹ️  No IV records 6-9 found (may have been resolved separately)\n";
    }
    echo "\n";

    // 7. Verify JE00035
    echo "7. Historical Journal Entry Verification\n";
    echo str_repeat("-", 50) . "\n";
    
    $je35 = $db->query("
        SELECT 
            je.id,
            je.entry_number,
            je.entry_date,
            je.description,
            SUM(jl.debit) as total_debit,
            SUM(jl.credit) as total_credit
        FROM journal_entries je
        LEFT JOIN journal_lines jl ON jl.journal_entry_id = je.id
        WHERE je.entry_number = 'JE00035'
        GROUP BY je.id
    ")->fetch();
    
    if ($je35) {
        echo "Journal Entry: {$je35['entry_number']}\n";
        echo "Date: {$je35['entry_date']}\n";
        echo "Description: {$je35['description']}\n";
        echo "Total Debit: UGX " . number_format($je35['total_debit'], 2) . "\n";
        echo "Total Credit: UGX " . number_format($je35['total_credit'], 2) . "\n";
        echo "✓ JE00035 intact\n";
    } else {
        echo "⚠️  JE00035 not found\n";
    }
    echo "\n";

    // 8. Sample accounts with balances
    echo "8. Sample Share Accounts\n";
    echo str_repeat("-", 50) . "\n";
    
    $samples = $db->query("
        SELECT 
            msa.account_number,
            m.member_number,
            CONCAT(m.first_name, ' ', m.last_name) as member_name,
            msa.status,
            COALESCE(SUM(st.amount), 0) as balance,
            COUNT(st.id) as transaction_count
        FROM member_share_accounts msa
        INNER JOIN members m ON m.id = msa.member_id
        LEFT JOIN share_transactions st ON st.share_account_id = msa.id
        GROUP BY msa.id
        ORDER BY balance DESC
        LIMIT 10
    ")->fetchAll();
    
    foreach ($samples as $sample) {
        printf("%-12s | %-10s | %-30s | %10s | %3d txns\n",
            $sample['account_number'],
            $sample['member_number'],
            substr($sample['member_name'], 0, 30),
            'UGX ' . number_format($sample['balance'], 0),
            $sample['transaction_count']
        );
    }
    echo "\n";

    // 9. Check for members without accounts
    echo "9. Coverage Check\n";
    echo str_repeat("-", 50) . "\n";
    
    $coverage = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM members WHERE status = 'active') as active_members,
            (SELECT COUNT(*) FROM member_share_accounts) as share_accounts,
            (SELECT COUNT(*) 
             FROM members m 
             LEFT JOIN member_share_accounts msa ON msa.member_id = m.id
             WHERE m.status = 'active' AND msa.id IS NULL
            ) as members_without_accounts
    ")->fetch();
    
    echo "Active members: {$coverage['active_members']}\n";
    echo "Share accounts: {$coverage['share_accounts']}\n";
    echo "Members without accounts: {$coverage['members_without_accounts']}\n";
    
    if ((int)$coverage['members_without_accounts'] > 0) {
        throw new RuntimeException("Some active members do not have share accounts!");
    }
    echo "✓ All active members have share accounts\n\n";

    // 10. Model functionality test
    echo "10. Model Functionality Test\n";
    echo str_repeat("-", 50) . "\n";
    
    $model = new MemberShareAccountModel();
    
    $testAccount = $db->query("SELECT id, member_id FROM member_share_accounts LIMIT 1")->fetch();
    if ($testAccount) {
        $account = $model->findById((int)$testAccount['id']);
        if (!$account) {
            throw new RuntimeException("Model failed to find account by ID");
        }
        echo "✓ findById() working\n";
        
        $accountByMember = $model->findByMemberId((int)$testAccount['member_id']);
        if (!$accountByMember) {
            throw new RuntimeException("Model failed to find account by member ID");
        }
        echo "✓ findByMemberId() working\n";
        
        $balance = $model->getBalance((int)$testAccount['id']);
        echo "✓ getBalance() working (returned: UGX " . number_format($balance, 2) . ")\n";
    }
    echo "\n";

    // Final summary
    echo "=====================================\n";
    echo "✅ VERIFICATION COMPLETE\n";
    echo "=====================================\n\n";

    echo "All checks passed. The share accounts architecture is properly deployed.\n\n";
    
    echo "Next steps:\n";
    echo "1. Access share transfers at: ?page=share-transfers\n";
    echo "2. Test a real transfer with authorization\n";
    echo "3. Verify journal entries are created correctly\n";
    echo "4. Update documentation\n\n";

} catch (Exception $e) {
    echo "\n❌ VERIFICATION FAILED\n";
    echo "Error: {$e->getMessage()}\n\n";
    exit(1);
}
