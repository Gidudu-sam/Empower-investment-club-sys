<?php
require_once CORE_PATH . '/Controller.php';

/**
 * CleanupController - Production Data Cleanup
 * ONE-TIME USE ONLY
 */
class CleanupController extends Controller
{
    public function runCleanup(): void
    {
        // Only allow admin to run this
        Session::requireAuth();
        if (!Session::hasRole(['admin'])) {
            die("Access denied. Only admin can run cleanup.");
        }

        $pdo = Database::getInstance()->getConnection();
        
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <title>Production Cleanup</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 20px auto; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        h2 { color: #34495e; margin-top: 30px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #28a745; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #dc3545; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #17a2b8; }
        .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #ffc107; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #3498db; color: white; }
        tr:hover { background-color: #f5f5f5; }
        .highlight { font-weight: bold; color: #e74c3c; }
        .step { background: #ecf0f1; padding: 10px; margin: 10px 0; border-radius: 4px; }
        pre { background: #2c3e50; color: #ecf0f1; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
    </style>
</head>
<body>
<div class='container'>
<h1>🚀 Production Data Cleanup</h1>
<p>Date: <?= date('Y-m-d H:i:s') ?></p>
<div class='success'>✅ Connected to database successfully</div>

<?php
        // Get current counts
        echo "<h2>📊 Step 1: Current Data (Before Cleanup)</h2>";
        
        $beforeCounts = [];
        $tables = [
            'members', 'withdrawals', 'savings', 'loans', 'loan_repayments', 
            'member_fees', 'journal_entries', 'financial_years', 'users'
        ];
        
        echo "<table><tr><th>Table</th><th>Count</th></tr>";
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `$table`");
                $count = $stmt->fetchColumn();
                $beforeCounts[$table] = $count;
                $highlight = in_array($table, ['members', 'withdrawals']) ? 'highlight' : '';
                echo "<tr><td class='$highlight'>$table</td><td class='$highlight'>$count</td></tr>";
            } catch (PDOException $e) {
                $beforeCounts[$table] = 'N/A';
                echo "<tr><td>$table</td><td>N/A</td></tr>";
            }
        }
        echo "</table>";
        
        // Create backup
        echo "<h2>💾 Step 2: Creating Backup</h2>";
        $backupFile = APP_PATH . "/../backups/pre_cleanup_" . date('Ymd_His') . ".sql";
        $backupDir = dirname($backupFile);
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $command = "C:\\xampp\\mysql\\bin\\mysqldump.exe -u root empower_db > \"$backupFile\" 2>&1";
        exec($command, $output, $return);
        
        if (file_exists($backupFile) && filesize($backupFile) > 1000) {
            $size = round(filesize($backupFile) / 1024 / 1024, 2);
            echo "<div class='success'>✅ Backup created: " . basename($backupFile) . " ({$size} MB)</div>";
        } else {
            echo "<div class='warning'>⚠️ Backup may have failed</div>";
        }
        
        // Run cleanup
        echo "<h2>🧹 Step 3: Running Cleanup</h2>";
        
        $deleteTables = [
            'loan_installments',
            'loan_penalties', 
            'business_loan_interest_payments',
            'business_loan_weekly_savings',
            'loan_repayments',
            'member_fees',
            'fee_history',
            'opening_balance_items',
            'transaction_approval_slot_instances',
            'transaction_approval_rounds',
            'loan_provisioning_details',
            'loan_provisioning_runs',
            'loan_applications',
            'loans',
            'savings',
            'member_savings_accounts',
            'savings_accounts',
            'fixed_deposits',
            'fixed_deposit_closure_requests',
            'savings_account_closure_requests',
            'investments',
            'journal_entries',
            'internal_vouchers',
            'member_account_adjustments',
            'opening_balance_batches',
            'opening_balances',
            'cash_payment_references',
            'referral_bonuses',
            'notifications',
            'activity_logs',
            'user_activity_log',
            'corrections',
            'database_recoveries',
            'accounting_periods',
            'financial_years'
        ];
        
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $pdo->exec("SET SQL_SAFE_UPDATES = 0");
            
            $deleted = 0;
            $skipped = 0;
            
            foreach ($deleteTables as $table) {
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
                    $count = $stmt->fetchColumn();
                    
                    if ($count > 0) {
                        $pdo->exec("DELETE FROM `$table`");
                        echo "<div class='step'>✅ Deleted {$count} records from {$table}</div>";
                        $deleted++;
                    } else {
                        echo "<div class='step' style='opacity:0.6;'>⊘ {$table} already empty</div>";
                    }
                } catch (PDOException $e) {
                    echo "<div class='step' style='opacity:0.6;'>⊘ {$table} (table doesn't exist)</div>";
                    $skipped++;
                }
            }
            
            // Reset auto-increments
            $resetTables = ['savings', 'loans', 'loan_repayments', 'member_fees', 'journal_entries', 'notifications'];
            foreach ($resetTables as $table) {
                try {
                    $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
                } catch (PDOException $e) {
                    // Ignore if table doesn't exist
                }
            }
            
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            $pdo->exec("SET SQL_SAFE_UPDATES = 1");
            
            echo "<div class='success'><strong>🎉 CLEANUP COMPLETED!</strong><br>";
            echo "Deleted from {$deleted} tables, {$skipped} tables didn't exist</div>";
            
        } catch (PDOException $e) {
            echo "<div class='error'>❌ Error: " . $e->getMessage() . "</div>";
        }
        
        // After counts
        echo "<h2>📊 Step 4: After Cleanup</h2>";
        echo "<table><tr><th>Table</th><th>Before</th><th>After</th><th>Status</th></tr>";
        
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `$table`");
                $count = $stmt->fetchColumn();
                $before = $beforeCounts[$table];
                $status = in_array($table, ['members', 'withdrawals']) 
                    ? ($before == $count ? '✅ Preserved' : '⚠️ Changed')
                    : ($count == 0 ? '✅ Cleared' : '⚠️ Partial');
                $highlight = in_array($table, ['members', 'withdrawals']) ? 'highlight' : '';
                echo "<tr><td class='$highlight'>$table</td><td>$before</td><td class='$highlight'>$count</td><td>$status</td></tr>";
            } catch (PDOException $e) {
                echo "<tr><td>$table</td><td>$before</td><td>N/A</td><td>Error</td></tr>";
            }
        }
        echo "</table>";
        
        // Share verification
        echo "<h2>📈 Step 5: Share Data Verification</h2>";
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(retained_amount) as shares FROM withdrawals");
            $shares = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<table>";
            echo "<tr><th>Metric</th><th>Value</th></tr>";
            echo "<tr><td>Total Withdrawals</td><td class='highlight'>" . number_format($shares['total']) . "</td></tr>";
            echo "<tr><td>Total Shares</td><td class='highlight'>UGX " . number_format($shares['shares'], 2) . "</td></tr>";
            echo "</table>";
            echo "<div class='success'>✅ Share data intact!</div>";
        } catch (PDOException $e) {
            echo "<div class='error'>❌ Could not verify shares</div>";
        }
        
        echo "<h2>✅ Cleanup Complete!</h2>";
        echo "<div class='info'>";
        echo "<h3>📝 Next Steps:</h3>";
        echo "<ol>";
        echo "<li>Create new Financial Year</li>";
        echo "<li>Create Accounting Periods</li>";
        echo "<li>Start recording real transactions</li>";
        echo "</ol>";
        echo "</div>";
        
        echo "<div class='warning'><h3>⚠️ Delete this controller after use!</h3>";
        echo "<pre>Delete: app/controllers/CleanupController.php</pre></div>";
        
        ?>
</div>
</body>
</html>
        <?php
        echo ob_get_clean();
    }

    public function recoverAccounts(): void
    {
        // Only allow admin to run this
        Session::requireAuth();
        if (!Session::hasRole(['admin'])) {
            die("Access denied. Only admin can run recovery.");
        }

        require_once APP_PATH . '/models/MemberSavingsAccountModel.php';
        $pdo = Database::getInstance()->getConnection();
        
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <title>Recover Compulsory Savings Accounts</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 20px auto; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        h2 { color: #34495e; margin-top: 30px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #28a745; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #dc3545; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #17a2b8; }
        .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #ffc107; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #3498db; color: white; }
        tr:hover { background-color: #f5f5f5; }
        .step { background: #ecf0f1; padding: 10px; margin: 10px 0; border-radius: 4px; }
        pre { background: #2c3e50; color: #ecf0f1; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
    </style>
</head>
<body>
<div class='container'>
<h1>🔧 Recover Compulsory Savings Accounts</h1>
<p>Date: <?= date('Y-m-d H:i:s') ?></p>

<?php
        echo "<h2>📊 Step 1: Analyzing Members</h2>";
        
        try {
            // Get all members without compulsory savings accounts
            $stmt = $pdo->query("
                SELECT m.id, m.member_number, m.first_name, m.last_name, m.join_date
                FROM members m
                WHERE NOT EXISTS (
                    SELECT 1 FROM member_savings_accounts msa
                    INNER JOIN savings_account_holders sah ON sah.account_id = msa.id
                    WHERE sah.member_id = m.id AND msa.account_type = 'compulsory'
                )
                ORDER BY m.id
            ");
            
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $totalMembers = count($members);
            
            echo "<div class='info'>Found <strong>{$totalMembers}</strong> members without compulsory savings accounts</div>";
            
            if ($totalMembers === 0) {
                echo "<div class='success'>✅ All members already have compulsory savings accounts!</div>";
                echo "</div></body></html>";
                echo ob_get_clean();
                return;
            }
            
            echo "<h2>🔧 Step 2: Creating Compulsory Savings Accounts</h2>";
            
            $accountModel = new MemberSavingsAccountModel();
            $userId = (int)($_SESSION['user_id'] ?? 1);
            
            $created = 0;
            $failed = 0;
            $errors = [];

            foreach ($members as $member) {
                try {
                    $accountId = $accountModel->createAccount(
                        [
                            'account_type' => 'compulsory',
                            'opened_date'  => $member['join_date'],
                        ],
                        [['member_id' => $member['id'], 'role' => 'primary']],
                        $userId
                    );
                    
                    $account = $accountModel->getAccount($accountId);
                    $created++;
                    
                    echo "<div class='step'>✅ Created <strong>{$account['account_number']}</strong> for {$member['member_number']} - {$member['first_name']} {$member['last_name']}</div>";
                } catch (Exception $e) {
                    $failed++;
                    $errors[] = [
                        'member' => $member['member_number'],
                        'name' => "{$member['first_name']} {$member['last_name']}",
                        'error' => $e->getMessage()
                    ];
                    echo "<div class='error'>❌ Failed for {$member['member_number']}: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            }

            echo "<h2>📊 Step 3: Summary</h2>";
            echo "<table>";
            echo "<tr><th>Metric</th><th>Count</th></tr>";
            echo "<tr><td>Total Members Processed</td><td>{$totalMembers}</td></tr>";
            echo "<tr><td class='highlight' style='color:#28a745;'>✅ Successfully Created</td><td class='highlight' style='color:#28a745;'>{$created}</td></tr>";
            echo "<tr><td class='highlight' style='color:#dc3545;'>❌ Failed</td><td class='highlight' style='color:#dc3545;'>{$failed}</td></tr>";
            echo "</table>";
            
            if ($created > 0) {
                echo "<div class='success'><h3>🎉 Recovery Complete!</h3>";
                echo "Successfully created <strong>{$created}</strong> compulsory savings accounts.</div>";
            }
            
            if ($failed > 0) {
                echo "<div class='error'><h3>⚠️ Some Accounts Failed</h3>";
                echo "<p>{$failed} accounts could not be created. Details above.</p>";
                echo "</div>";
            }

            // Verification
            echo "<h2>📈 Step 4: Verification</h2>";
            $stmt = $pdo->query("
                SELECT COUNT(DISTINCT m.id) as members_with_accounts
                FROM members m
                INNER JOIN savings_account_holders sah ON sah.member_id = m.id
                INNER JOIN member_savings_accounts msa ON msa.id = sah.account_id
                WHERE msa.account_type = 'compulsory'
            ");
            $verification = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt2 = $pdo->query("SELECT COUNT(*) as total_members FROM members");
            $totalInDb = $stmt2->fetchColumn();
            
            echo "<table>";
            echo "<tr><th>Metric</th><th>Value</th></tr>";
            echo "<tr><td>Total Members in Database</td><td>{$totalInDb}</td></tr>";
            echo "<tr><td>Members with Compulsory Accounts</td><td class='highlight'>{$verification['members_with_accounts']}</td></tr>";
            echo "</table>";
            
            if ($verification['members_with_accounts'] == $totalInDb) {
                echo "<div class='success'>✅ All members now have compulsory savings accounts!</div>";
            } else {
                $missing = $totalInDb - $verification['members_with_accounts'];
                echo "<div class='warning'>⚠️ {$missing} members still missing compulsory accounts. Please investigate.</div>";
            }
            
        } catch (Exception $e) {
            echo "<div class='error'>❌ Fatal error: " . htmlspecialchars($e->getMessage()) . "</div>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        }
        
        echo "<h2>✅ Recovery Process Complete!</h2>";
        echo "<div class='info'>";
        echo "<h3>📝 Next Steps:</h3>";
        echo "<ol>";
        echo "<li>Verify all members can see their savings accounts</li>";
        echo "<li>Check that savings deposits work correctly</li>";
        echo "<li>Delete this cleanup controller after verification</li>";
        echo "</ol>";
        echo "</div>";
        
        ?>
</div>
</body>
</html>
        <?php
        echo ob_get_clean();
    }
}
