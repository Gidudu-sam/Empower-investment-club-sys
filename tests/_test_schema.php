<?php
set_time_limit(10);
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

// Test time for each table
$tables = ['loans','loan_installments','loan_repayments','loan_types','loan_product_settings',
    'loan_product_rules','loan_interest_brackets','journal_entries','journal_lines',
    'journal_entry_audit','journal_number_sequences','accounts','users','roles',
    'accounting_periods','financial_years','activity_logs','members','member_savings_accounts',
    'savings','notifications','member_fees','loan_penalties','loan_weekly_interest',
    'loan_weekly_savings','business_loan_interest_payments','business_loan_weekly_savings'];

foreach ($tables as $t) {
    $start = microtime(true);
    try {
        $r = $pdo->query("SHOW CREATE TABLE `empower_db`.`{$t}`")->fetch();
        $sql = $r['Create Table'] ?? ($r[1] ?? '');
        $ms = round((microtime(true)-$start)*1000, 1);
        echo "  OK: {$t} ({$ms}ms) " . strlen($sql) . " bytes\n";
    } catch (Throwable $e) {
        $ms = round((microtime(true)-$start)*1000, 1);
        echo "  ERR: {$t} ({$ms}ms) " . $e->getMessage() . "\n";
    }
}
echo "DONE\n";
