<?php
require_once CORE_PATH . '/Controller.php';

/**
 * SystemTestController - Critical User Flow Testing
 * USE BEFORE DEPLOYMENT - Test all critical functionality
 */
class SystemTestController extends Controller
{
    private PDO $db;
    private array $testResults = [];
    private array $errors = [];

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function runTests(): void
    {
        // Only allow admin
        Session::requireAuth();
        if (!Session::hasRole(['admin'])) {
            die("Access denied. Only admin can run system tests.");
        }

        ob_start();
        $this->renderHeader();
        
        echo "<h2>🧪 Running Critical User Flow Tests</h2>";
        echo "<p class='text-muted'>Testing system before production deployment...</p>";
        
        // Run all tests
        $this->testDatabaseConnection();
        $this->testConfiguration();
        $this->testMasterData();
        $this->testAuthentication();
        $this->testMemberManagement();
        $this->testSavingsAccounts();
        $this->testLoanSystem();
        $this->testAccountingIntegrity();
        $this->testSecuritySettings();
        
        // Render results
        $this->renderResults();
        $this->renderFooter();
        
        echo ob_get_clean();
    }

    private function testDatabaseConnection(): void
    {
        try {
            $stmt = $this->db->query("SELECT VERSION() as version");
            $version = $stmt->fetchColumn();
            $this->pass("Database Connection", "MySQL $version");
        } catch (PDOException $e) {
            $this->fail("Database Connection", $e->getMessage());
        }
    }

    private function testConfiguration(): void
    {
        // Check environment
        $env = APP_ENV;
        if ($env === 'development') {
            $this->warn("Environment", "Currently in DEVELOPMENT mode (set APP_ENV=production)");
        } else {
            $this->pass("Environment", "Running in $env mode");
        }

        // Check APP_URL
        $url = APP_URL;
        if (strpos($url, 'localhost') !== false) {
            $this->warn("Application URL", "$url (Update APP_URL for production)");
        } else {
            $this->pass("Application URL", $url);
        }

        // Check session settings
        if (APP_ENV === 'production') {
            $httpOnly = ini_get('session.cookie_httponly');
            $secure = ini_get('session.cookie_secure');
            if ($httpOnly && $secure) {
                $this->pass("Session Security", "HttpOnly and Secure flags enabled");
            } else {
                $this->warn("Session Security", "Flags not fully enabled (production only)");
            }
        }
    }

    private function testMasterData(): void
    {
        // Chart of Accounts (stored in 'accounts' table)
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM accounts WHERE is_active = 1");
            $count = $stmt->fetchColumn();
            if ($count > 0) {
                $this->pass("Chart of Accounts", "$count active accounts");
            } else {
                $this->warn("Chart of Accounts", "No active accounts (table exists but empty)");
            }
        } catch (PDOException $e) {
            $this->warn("Chart of Accounts", "Table not found (may need setup)");
        }

        // Loan Types
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM loan_types WHERE is_active = 1");
            $count = $stmt->fetchColumn();
            if ($count > 0) {
                $this->pass("Loan Types", "$count configured");
            } else {
                $this->warn("Loan Types", "No loan types configured");
            }
        } catch (PDOException $e) {
            $this->warn("Loan Types", "Table not found");
        }

        // Fees
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM fees WHERE is_active = 1");
            $count = $stmt->fetchColumn();
            if ($count > 0) {
                $this->pass("Fees", "$count configured");
            } else {
                $this->warn("Fees", "No fees configured");
            }
        } catch (PDOException $e) {
            $this->warn("Fees", "Table not found");
        }

        // Users (critical - must exist)
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
            $count = $stmt->fetchColumn();
            if ($count > 0) {
                $this->pass("Active Users", "$count users");
            } else {
                $this->fail("Active Users", "No active users");
            }
        } catch (PDOException $e) {
            $this->fail("Active Users", "Users table not found: " . $e->getMessage());
        }

        // Roles (critical - must exist)
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM roles");
            $count = $stmt->fetchColumn();
            if ($count >= 11) {
                $this->pass("User Roles", "$count roles configured");
            } else {
                $this->warn("User Roles", "Expected 11 roles, found $count");
            }
        } catch (PDOException $e) {
            $this->fail("User Roles", "Roles table not found: " . $e->getMessage());
        }
    }

    private function testAuthentication(): void
    {
        // Check if admin user exists (users have direct role_id column)
        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) 
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE u.is_active = 1 AND r.name = 'admin'
            ");
            $count = $stmt->fetchColumn();
            if ($count > 0) {
                $this->pass("Admin Account", "$count admin user(s) exist");
            } else {
                $this->fail("Admin Account", "No admin user found");
            }
        } catch (PDOException $e) {
            $this->fail("Admin Account", "Error checking admin: " . $e->getMessage());
        }

        // Current user logged in
        $userId = Session::get('user_id');
        $userRole = Session::get('user_role');  // Changed from 'role' to 'user_role'
        if ($userId && $userRole) {
            $this->pass("Current Session", "Logged in as user ID $userId ($userRole)");
        } else {
            $this->fail("Current Session", "Session data incomplete");
        }
    }

    private function testMemberManagement(): void
    {
        // Check members exist
        $stmt = $this->db->query("SELECT COUNT(*) FROM members");
        $memberCount = $stmt->fetchColumn();
        if ($memberCount > 0) {
            $this->pass("Members", "$memberCount members in database");
        } else {
            $this->warn("Members", "No members found (expected if fresh install)");
        }

        // Check compulsory accounts match members
        if ($memberCount > 0) {
            $stmt = $this->db->query("
                SELECT COUNT(DISTINCT sah.member_id) 
                FROM savings_account_holders sah
                INNER JOIN member_savings_accounts msa ON msa.id = sah.account_id
                WHERE msa.account_type = 'compulsory'
            ");
            $accountCount = $stmt->fetchColumn();
            
            if ($accountCount == $memberCount) {
                $this->pass("Compulsory Accounts", "All $memberCount members have accounts");
            } else {
                $missing = $memberCount - $accountCount;
                $this->fail("Compulsory Accounts", "$missing members missing accounts");
            }
        }
    }

    private function testSavingsAccounts(): void
    {
        // Savings account types are stored in a constant/config, not a database table
        // So we skip that check
        
        // Check for test transaction data
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM savings");
            $count = $stmt->fetchColumn();
            if ($count == 0) {
                $this->pass("Savings Transactions", "Clean (no test data)");
            } else {
                $this->warn("Savings Transactions", "$count transactions exist (expected 0 for fresh production)");
            }
        } catch (PDOException $e) {
            $this->warn("Savings Transactions", "Table not found");
        }
    }

    private function testLoanSystem(): void
    {
        // Check for test loans
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM loans");
            $count = $stmt->fetchColumn();
            if ($count == 0) {
                $this->pass("Loan Records", "Clean (no test data)");
            } else {
                $this->warn("Loan Records", "$count loans exist (expected 0 for fresh production)");
            }
        } catch (PDOException $e) {
            $this->warn("Loan Records", "Table not found");
        }

        // Check for test repayments
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM loan_repayments");
            $count = $stmt->fetchColumn();
            if ($count == 0) {
                $this->pass("Loan Repayments", "Clean (no test data)");
            } else {
                $this->warn("Loan Repayments", "$count repayments exist (expected 0 for fresh production)");
            }
        } catch (PDOException $e) {
            $this->warn("Loan Repayments", "Table not found");
        }
    }

    private function testAccountingIntegrity(): void
    {
        // Check journal entries
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM journal_entries");
            $jeCount = $stmt->fetchColumn();
            if ($jeCount == 0) {
                $this->pass("Journal Entries", "Clean (no test data)");
            } else {
                $this->warn("Journal Entries", "$jeCount entries exist");
                
                // Verify debit = credit only if journal entries exist
                try {
                    $stmt = $this->db->query("
                        SELECT 
                            SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE 0 END) as total_debits,
                            SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END) as total_credits
                        FROM journal_entry_lines
                    ");
                    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($totals['total_debits'] == $totals['total_credits']) {
                        $this->pass("Accounting Balance", "Debits = Credits (balanced)");
                    } else {
                        $this->fail("Accounting Balance", "UNBALANCED! Debits ≠ Credits");
                    }
                } catch (PDOException $e) {
                    $this->warn("Accounting Balance", "Cannot verify (journal_entry_lines table issue)");
                }
            }
        } catch (PDOException $e) {
            $this->warn("Journal Entries", "Table not found (accounting not initialized)");
        }

        // Check financial years
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM financial_years");
            $count = $stmt->fetchColumn();
            if ($count == 0) {
                $this->warn("Financial Years", "None configured (create on production)");
            } else {
                $this->pass("Financial Years", "$count financial year(s)");
            }
        } catch (PDOException $e) {
            $this->warn("Financial Years", "Table not found");
        }
    }

    private function testSecuritySettings(): void
    {
        // Check .htaccess exists
        $htaccessPath = ROOT_PATH . '/.htaccess';
        if (file_exists($htaccessPath)) {
            $this->pass("Security File", ".htaccess exists");
            
            // Check if it blocks sensitive directories
            $content = file_get_contents($htaccessPath);
            if (strpos($content, 'RewriteRule ^(app|core|database)') !== false) {
                $this->pass("Directory Protection", "app/core/database blocked");
            } else {
                $this->warn("Directory Protection", "Check .htaccess rules");
            }
        } else {
            $this->fail("Security File", ".htaccess missing");
        }

        // Check for test files in root
        $testFiles = [
            'test_connection.php',
            'audit_loan_structure.php', 
            'run_cleanup.php',
            'check_loan_approval_status.php'
        ];
        $found = [];
        foreach ($testFiles as $file) {
            if (file_exists(ROOT_PATH . '/' . $file)) {
                $found[] = $file;
            }
        }
        
        if (empty($found)) {
            $this->pass("Test Files", "No test files in root (cleaned up)");
        } else {
            $this->warn("Test Files", "Delete: " . implode(', ', $found));
        }
    }

    private function pass(string $test, string $message): void
    {
        $this->testResults[] = [
            'status' => 'pass',
            'test' => $test,
            'message' => $message
        ];
    }

    private function fail(string $test, string $message): void
    {
        $this->testResults[] = [
            'status' => 'fail',
            'test' => $test,
            'message' => $message
        ];
        $this->errors[] = $test;
    }

    private function warn(string $test, string $message): void
    {
        $this->testResults[] = [
            'status' => 'warn',
            'test' => $test,
            'message' => $message
        ];
    }

    private function renderResults(): void
    {
        $passCount = count(array_filter($this->testResults, fn($r) => $r['status'] === 'pass'));
        $failCount = count(array_filter($this->testResults, fn($r) => $r['status'] === 'fail'));
        $warnCount = count(array_filter($this->testResults, fn($r) => $r['status'] === 'warn'));
        $totalCount = count($this->testResults);
        $passPercent = $totalCount > 0 ? round(($passCount / $totalCount) * 100) : 0;

        echo "<h2>📊 Test Results</h2>";
        
        // Summary
        echo "<div class='row mb-4'>";
        echo "<div class='col-md-3'><div class='card text-center border-success'><div class='card-body'><h3 class='text-success mb-0'>$passCount</h3><small>Passed</small></div></div></div>";
        echo "<div class='col-md-3'><div class='card text-center border-danger'><div class='card-body'><h3 class='text-danger mb-0'>$failCount</h3><small>Failed</small></div></div></div>";
        echo "<div class='col-md-3'><div class='card text-center border-warning'><div class='card-body'><h3 class='text-warning mb-0'>$warnCount</h3><small>Warnings</small></div></div></div>";
        echo "<div class='col-md-3'><div class='card text-center border-primary'><div class='card-body'><h3 class='text-primary mb-0'>$passPercent%</h3><small>Success Rate</small></div></div></div>";
        echo "</div>";

        // Detailed results
        echo "<table class='table table-sm'><thead><tr><th>Status</th><th>Test</th><th>Result</th></tr></thead><tbody>";
        foreach ($this->testResults as $result) {
            $icon = $result['status'] === 'pass' ? '✅' : ($result['status'] === 'fail' ? '❌' : '⚠️');
            $class = $result['status'] === 'pass' ? 'table-success' : ($result['status'] === 'fail' ? 'table-danger' : 'table-warning');
            echo "<tr class='$class'><td>$icon</td><td><strong>{$result['test']}</strong></td><td>{$result['message']}</td></tr>";
        }
        echo "</tbody></table>";

        // Final verdict
        if ($failCount == 0 && $warnCount == 0) {
            echo "<div class='alert alert-success'><h4>🎉 ALL TESTS PASSED!</h4><p>System is ready for production deployment.</p></div>";
        } elseif ($failCount == 0) {
            echo "<div class='alert alert-warning'><h4>⚠️ WARNINGS FOUND</h4><p>System is mostly ready, but review warnings before deployment.</p></div>";
        } else {
            echo "<div class='alert alert-danger'><h4>❌ CRITICAL ISSUES</h4><p>Fix these issues before deployment: " . implode(', ', $this->errors) . "</p></div>";
        }

        // Recommendations
        echo "<h3>📝 Recommendations</h3><ul class='small'>";
        if ($failCount > 0) {
            echo "<li class='text-danger'>Fix all failed tests before deployment</li>";
        }
        if ($warnCount > 0) {
            echo "<li class='text-warning'>Review and address warnings</li>";
        }
        echo "<li>Create final database backup</li>";
        echo "<li>Set production environment variables</li>";
        echo "<li>Delete test files (if any found)</li>";
        echo "<li>Verify HTTPS is working</li>";
        echo "<li>Test login after deployment</li>";
        echo "</ul>";
    }

    private function renderHeader(): void
    {
        ?>
<!DOCTYPE html>
<html>
<head>
    <title>System Tests - Empower</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        .table-success td { background: #d4edda !important; }
        .table-danger td { background: #f8d7da !important; }
        .table-warning td { background: #fff3cd !important; }
    </style>
</head>
<body>
<div class='container'>
<h1>🧪 System Tests - Pre-Deployment</h1>
<p class='text-muted'>Date: <?= date('Y-m-d H:i:s') ?> | Environment: <?= APP_ENV ?></p>
        <?php
    }

    private function renderFooter(): void
    {
        ?>
<hr>
<p class='text-muted small'>Generated: <?= date('Y-m-d H:i:s') ?> | Empower SACCO v<?= APP_VERSION ?></p>
</div>
</body>
</html>
        <?php
    }
}
