<?php
// READ-ONLY AUDIT SCRIPT - Business Loan Repayment Methods
require_once __DIR__ . '/app/config/database.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "=== BUSINESS LOAN REPAYMENT AUDIT ===\n";
    echo "Date: " . date('Y-m-d H:i:s') . "\n\n";
    
    // 1. Check loans table structure
    echo "--- 1. LOANS TABLE STRUCTURE ---\n";
    $stmt = $pdo->query("DESCRIBE loans");
    $relevantColumns = ['repayment_method', 'repayment_frequency', 'interest_only_months', 
                        'principal_recovery_weeks', 'interest_mode', 'fixed_interest_amount',
                        'loan_amount', 'interest_rate', 'interest_amount', 'total_payable',
                        'monthly_installment', 'weekly_savings_amount', 'loan_period_months'];
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (in_array($row['Field'], $relevantColumns)) {
            echo sprintf("%-30s %-20s %s\n", $row['Field'], $row['Type'], $row['Null']);
        }
    }
    
    // 2. Check loan_installments structure
    echo "\n--- 2. LOAN_INSTALLMENTS TABLE STRUCTURE ---\n";
    $stmt = $pdo->query("DESCRIBE loan_installments");
    $installmentCols = ['period_type', 'payment_type', 'principal_due', 'interest_due', 
                        'amount_due', 'balance_after', 'month_covered', 'week_number'];
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (in_array($row['Field'], $installmentCols)) {
            echo sprintf("%-20s %-30s %s\n", $row['Field'], $row['Type'], $row['Null']);
        }
    }
    
    // 3. Check loan_interest_brackets
    echo "\n--- 3. INTEREST BRACKETS CONFIGURATION ---\n";
    $stmt = $pdo->query("
        SELECT lt.name as loan_type, 
               lb.min_amount, lb.max_amount, lb.monthly_rate, lb.is_active, lb.sort_order
        FROM loan_interest_brackets lb
        JOIN loan_types lt ON lt.id = lb.loan_type_id
        WHERE lb.is_active = 1
        ORDER BY lt.id, lb.sort_order
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("%-20s: UGX %s - %s = %.2f%% per month\n", 
            $row['loan_type'],
            number_format($row['min_amount'], 0),
            $row['max_amount'] ? number_format($row['max_amount'], 0) : 'unlimited',
            $row['monthly_rate']
        );
    }
    
    // 4. Check existing loans count
    echo "\n--- 4. EXISTING LOANS DATA ---\n";
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM loans");
    $total = $stmt->fetchColumn();
    echo "Total loans in database: $total\n";
    
    // 5. Check repayment methods distribution
    echo "\n--- 5. REPAYMENT METHODS DISTRIBUTION ---\n";
    $stmt = $pdo->query("
        SELECT repayment_method, repayment_frequency, COUNT(*) as count
        FROM loans
        GROUP BY repayment_method, repayment_frequency
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("%-20s %-15s: %d loan(s)\n", 
            $row['repayment_method'], $row['repayment_frequency'], $row['count']);
    }
    
    // 6. Sample business loan (if exists)
    echo "\n--- 6. SAMPLE BUSINESS LOAN DATA ---\n";
    $stmt = $pdo->query("
        SELECT l.id, l.loan_number, l.loan_amount, l.interest_rate, l.interest_amount,
               l.total_payable, l.loan_period_months, l.repayment_method, l.repayment_frequency,
               l.interest_only_months, l.principal_recovery_weeks, l.monthly_installment,
               l.weekly_savings_amount, lt.name as loan_type
        FROM loans l
        LEFT JOIN loan_types lt ON lt.id = l.loan_type_id
        WHERE l.repayment_method IN ('interest_only', 'business_boost')
        LIMIT 1
    ");
    
    $businessLoan = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($businessLoan) {
        foreach ($businessLoan as $key => $value) {
            echo sprintf("%-30s: %s\n", $key, $value ?? 'NULL');
        }
        
        // Check installments for this loan
        echo "\n--- 7. SAMPLE LOAN INSTALLMENT SCHEDULE ---\n";
        $stmt = $pdo->prepare("
            SELECT installment_no, period_type, payment_type, due_date, 
                   principal_due, interest_due, amount_due, balance_after
            FROM loan_installments
            WHERE loan_id = ?
            ORDER BY installment_no
            LIMIT 15
        ");
        $stmt->execute([$businessLoan['id']]);
        
        echo sprintf("%-5s %-10s %-20s %-12s %12s %12s %12s %12s\n", 
            '#', 'Period', 'Type', 'Due Date', 'Principal', 'Interest', 'Amount', 'Balance');
        echo str_repeat('-', 110) . "\n";
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo sprintf("%-5s %-10s %-20s %-12s %12s %12s %12s %12s\n",
                $row['installment_no'],
                $row['period_type'],
                $row['payment_type'],
                $row['due_date'],
                number_format($row['principal_due'], 0),
                number_format($row['interest_due'], 0),
                number_format($row['amount_due'], 0),
                number_format($row['balance_after'], 0)
            );
        }
    } else {
        echo "No business loans found in database.\n";
    }
    
    // 8. Check loan_types configuration
    echo "\n\n--- 8. LOAN TYPES CONFIGURATION ---\n";
    $stmt = $pdo->query("
        SELECT id, name, repayment_type, max_amount
        FROM loan_types
        WHERE is_active = 1
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("ID %d: %-25s Repayment: %-15s Max: %s\n",
            $row['id'],
            $row['name'],
            $row['repayment_type'],
            $row['max_amount'] ? 'UGX ' . number_format($row['max_amount'], 0) : 'Unlimited'
        );
    }
    
    echo "\n=== AUDIT COMPLETE ===\n";
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
