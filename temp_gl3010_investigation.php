<?php
/**
 * TEMPORARY GL 3010 INVESTIGATION SCRIPT
 * Purpose: Investigate UGX 4.97M discrepancy between share_transactions and GL 3010
 * TO BE DELETED AFTER AUDIT COMPLETION
 */

require_once __DIR__ . '/app/config/database.php';
require_once __DIR__ . '/core/Database.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    echo "=== GL 3010 DISCREPANCY INVESTIGATION ===\n";
    echo "Date: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Get account ID for 3010
    $stmt = $conn->query("SELECT id, code, name FROM accounts WHERE code = '3010'");
    $account3010 = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account3010) {
        echo "ERROR: Account 3010 not found\n";
        exit(1);
    }
    
    echo "Account: {$account3010['code']} - {$account3010['name']}\n";
    echo "Account ID: {$account3010['id']}\n\n";
    
    // Get ALL journal lines for 3010
    $stmt = $conn->prepare("
        SELECT 
            jl.id as line_id,
            jl.journal_entry_id,
            je.entry_number,
            je.entry_date,
            je.source_module,
            je.source_reference_type,
            je.source_reference_id,
            je.description as journal_description,
            jl.debit,
            jl.credit,
            jl.description as line_description,
            je.created_by,
            u.full_name as created_by_name,
            je.created_at,
            je.reversed,
            je.data_classification
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_entry_id = je.id
        LEFT JOIN users u ON je.created_by = u.id
        WHERE jl.account_id = ?
        ORDER BY je.entry_date, je.id, jl.id
    ");
    $stmt->execute([$account3010['id']]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "=== ALL JOURNAL ENTRIES AFFECTING GL 3010 ===\n\n";
    echo "Total Journal Lines: " . count($lines) . "\n\n";
    
    if (!$lines) {
        echo "No journal entries found for account 3010.\n";
    } else {
        $totalDebit = 0;
        $totalCredit = 0;
        $runningBalance = 0;
        
        foreach ($lines as $line) {
            $totalDebit += $line['debit'];
            $totalCredit += $line['credit'];
            $runningBalance += ($line['credit'] - $line['debit']);
            
            echo str_repeat('-', 120) . "\n";
            echo "Entry #: {$line['entry_number']} (ID: {$line['journal_entry_id']})\n";
            echo "Date: {$line['entry_date']}\n";
            echo "Module: " . ($line['source_module'] ?? 'NULL') . "\n";
            echo "Source: " . ($line['source_reference_type'] ?? 'NULL') . " / " . ($line['source_reference_id'] ?? 'NULL') . "\n";
            echo "Description: {$line['journal_description']}\n";
            echo "Line Description: " . ($line['line_description'] ?? 'NULL') . "\n";
            echo "Debit:  " . number_format($line['debit'], 2) . "\n";
            echo "Credit: " . number_format($line['credit'], 2) . "\n";
            echo "Running Balance: " . number_format($runningBalance, 2) . "\n";
            echo "Created By: " . ($line['created_by_name'] ?? 'Unknown') . " on {$line['created_at']}\n";
            echo "Reversed: " . ($line['reversed'] ? 'YES' : 'NO') . "\n";
            echo "Classification: {$line['data_classification']}\n";
            
            // Check if there's a corresponding share_transaction
            if ($line['source_reference_type'] && $line['source_reference_id']) {
                if ($line['source_module'] === 'shares' || $line['source_reference_type'] === 'share_transaction') {
                    $stmtCheck = $conn->prepare("
                        SELECT id, member_id, transaction_type, amount, transaction_date
                        FROM share_transactions
                        WHERE id = ?
                    ");
                    $stmtCheck->execute([$line['source_reference_id']]);
                    $shareTxn = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                    
                    if ($shareTxn) {
                        echo "  → Linked Share Transaction: ID {$shareTxn['id']}, Member {$shareTxn['member_id']}, ";
                        echo "{$shareTxn['transaction_type']}, UGX " . number_format($shareTxn['amount'], 2) . "\n";
                    } else {
                        echo "  → ⚠ Share Transaction ID {$line['source_reference_id']} NOT FOUND\n";
                    }
                }
            }
            echo "\n";
        }
        
        echo str_repeat('=', 120) . "\n";
        echo "SUMMARY\n";
        echo str_repeat('=', 120) . "\n";
        echo "Total Debits:  UGX " . number_format($totalDebit, 2) . "\n";
        echo "Total Credits: UGX " . number_format($totalCredit, 2) . "\n";
        echo "Net Balance:   UGX " . number_format($runningBalance, 2) . " (Credits - Debits)\n\n";
    }
    
    // Now compare with share_transactions
    echo "=== SHARE_TRANSACTIONS TOTAL ===\n\n";
    
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as txn_count,
            SUM(amount) as total_amount,
            SUM(CASE WHEN journal_entry_id IS NOT NULL THEN amount ELSE 0 END) as with_journal,
            SUM(CASE WHEN journal_entry_id IS NULL THEN amount ELSE 0 END) as without_journal
        FROM share_transactions
    ");
    $shareSummary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Total Share Transactions: {$shareSummary['txn_count']}\n";
    echo "Total Amount: UGX " . number_format($shareSummary['total_amount'], 2) . "\n";
    echo "  With journal_entry_id: UGX " . number_format($shareSummary['with_journal'], 2) . "\n";
    echo "  Without journal_entry_id: UGX " . number_format($shareSummary['without_journal'], 2) . "\n\n";
    
    // Calculate discrepancy
    echo "=== RECONCILIATION ===\n\n";
    echo "GL 3010 Balance:          UGX " . number_format($runningBalance, 2) . " (A)\n";
    echo "Share Transactions Total: UGX " . number_format($shareSummary['total_amount'], 2) . " (B)\n";
    echo "Discrepancy (A - B):      UGX " . number_format($runningBalance - $shareSummary['total_amount'], 2) . "\n\n";
    
    if (abs($runningBalance - $shareSummary['total_amount']) < 0.01) {
        echo "✓ RECONCILED\n";
    } else {
        echo "✗ DISCREPANCY DETECTED\n\n";
        echo "Possible Explanations:\n";
        echo "1. GL 3010 includes entries not yet in share_transactions\n";
        echo "2. Opening balance posted to GL but not transaction-by-transaction tracked\n";
        echo "3. Historical entries from before share_transactions table existed\n";
        echo "4. Equity contributions not classified as share transactions\n";
        echo "5. Data entry errors or duplicates\n";
    }
    
    // Check for opening balance entries
    echo "\n=== OPENING BALANCE ANALYSIS ===\n\n";
    
    $stmt = $conn->prepare("
        SELECT 
            je.id,
            je.entry_number,
            je.entry_date,
            je.description,
            jl.debit,
            jl.credit
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_entry_id = je.id
        WHERE jl.account_id = ?
            AND (je.description LIKE '%opening%' 
                 OR je.description LIKE '%brought forward%'
                 OR je.description LIKE '%balance%'
                 OR je.source_module IS NULL
                 OR je.entry_date < '2025-05-01')
        ORDER BY je.entry_date
    ");
    $stmt->execute([$account3010['id']]);
    $openingEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($openingEntries) {
        echo "Potential Opening Balance Entries:\n\n";
        foreach ($openingEntries as $entry) {
            echo "  Entry: {$entry['entry_number']} | Date: {$entry['entry_date']}\n";
            echo "  Description: {$entry['description']}\n";
            echo "  Debit: " . number_format($entry['debit'], 2) . " | Credit: " . number_format($entry['credit'], 2) . "\n\n";
        }
    } else {
        echo "No obvious opening balance entries found.\n";
    }
    
    echo "\n=== INVESTIGATION COMPLETE ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
