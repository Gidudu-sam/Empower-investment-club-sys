<?php
/**
 * Verification Script: Current Database State
 * Checks GL 3010 balance, orphaned lines, and EMP0002 duplicate
 */

require_once __DIR__ . '/app/config/database.php';
require_once __DIR__ . '/core/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "=== DATABASE STATE VERIFICATION ===\n";
    echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";
    
    // 1. Check GL 3010 current balance (account_id 24 is code '3010')
    $stmt = $db->query("SELECT SUM(credit - debit) as balance FROM journal_lines WHERE account_id = 24");
    $gl_balance = $stmt->fetch(PDO::FETCH_ASSOC)['balance'] ?? 0;
    
    echo "1. GL 3010 BALANCE\n";
    echo "   Current Balance: " . number_format($gl_balance, 2) . "\n\n";
    
    // 2. Count orphaned lines (journal_entry_id points to deleted entry)
    $orphaned_count = $db->query("
        SELECT COUNT(*) as count 
        FROM journal_lines jl
        LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
        WHERE je.id IS NULL
    ")->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo "2. ORPHANED LINES CHECK\n";
    echo "   Total Orphaned Lines: " . $orphaned_count . "\n";
    
    if ($orphaned_count > 0) {
        $orphaned_lines = $db->query("
            SELECT jl.*, a.code as account_code
            FROM journal_lines jl
            LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
            LEFT JOIN accounts a ON a.id = jl.account_id
            WHERE je.id IS NULL
            ORDER BY jl.account_id, jl.created_at
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\n   ORPHANED LINES DETAIL:\n";
        foreach ($orphaned_lines as $line) {
            echo sprintf("   - ID %d: Account %s, Amount %.2f (Dr: %.2f, Cr: %.2f), Entry ID: %s\n",
                $line['id'],
                $line['account_code'] ?? 'N/A',
                $line['credit'] - $line['debit'],
                $line['debit'],
                $line['credit'],
                $line['journal_entry_id']
            );
        }
    }
    echo "\n";
    
    // 3. Check GL 3010 line details
    $lines = $db->query("
        SELECT jl.*, je.entry_number, a.code as account_code
        FROM journal_lines jl
        LEFT JOIN journal_entries je ON jl.journal_entry_id = je.id
        LEFT JOIN accounts a ON a.id = jl.account_id
        WHERE jl.account_id = 24
        ORDER BY jl.created_at
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "3. GL 3010 LINE DETAILS\n";
    echo "   Total Lines: " . count($lines) . "\n\n";
    
    foreach ($lines as $line) {
        $status = !$line['entry_number'] ? 'ORPHANED' : 'VALID';
        echo sprintf("   - ID %d: %.2f (Dr: %.2f, Cr: %.2f) | Entry: %s | Status: %s\n",
            $line['id'],
            $line['credit'] - $line['debit'],
            $line['debit'],
            $line['credit'],
            $line['entry_number'] ?? 'N/A',
            $status
        );
    }
    echo "\n";
    
    // 4. Check for EMP0002 duplicate in share_transactions
    $emp0002_records = $db->query("
        SELECT * FROM share_transactions 
        WHERE employee_id = 'EMP0002'
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "4. EMP0002 DUPLICATE CHECK\n";
    echo "   Total Records: " . count($emp0002_records) . "\n\n";
    
    if (count($emp0002_records) > 1) {
        echo "   ⚠️  DUPLICATE DETECTED:\n";
        foreach ($emp0002_records as $rec) {
            echo sprintf("   - ID %d: Amount %.2f, Date: %s, Type: %s\n",
                $rec['id'],
                $rec['amount'],
                $rec['transaction_date'],
                $rec['transaction_type']
            );
        }
    } else {
        echo "   ✓ No duplicate found\n";
    }
    echo "\n";
    
    // 5. Calculate total historical share capital
    $historical_total = $db->query("
        SELECT SUM(amount) as total 
        FROM share_transactions 
        WHERE employee_id != 'EMP0002'
    ")->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo "5. HISTORICAL SHARE CAPITAL\n";
    echo "   Total (excluding EMP0002): " . number_format($historical_total, 2) . "\n";
    
    $emp0002_total = $db->query("
        SELECT SUM(amount) as total 
        FROM share_transactions 
        WHERE employee_id = 'EMP0002'
    ")->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo "   EMP0002 Total: " . number_format($emp0002_total, 2) . "\n";
    echo "   Combined Total: " . number_format($historical_total + $emp0002_total, 2) . "\n\n";
    
    // 6. Summary
    echo "=== SUMMARY ===\n";
    echo "GL 3010 Balance: " . number_format($gl_balance, 2) . "\n";
    echo "Orphaned Lines: " . $orphaned_count . "\n";
    echo "EMP0002 Records: " . count($emp0002_records) . "\n";
    echo "Historical Recognition Needed: " . number_format($historical_total, 2) . "\n";
    echo "Database Status: " . ($orphaned_count == 0 ? "CLEAN ✓" : "CORRUPTED ✗") . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
