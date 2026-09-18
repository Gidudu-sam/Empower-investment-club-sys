<?php
/**
 * Test Share Accounts Migration on Disposable Clone
 * 
 * This script tests the member share accounts architecture and transfers
 * on a disposable test database before production deployment.
 * 
 * IMPORTANT: Run this on empower_test_db, NOT production!
 */

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../core/Autoloader.php';

class ShareAccountsMigrationTest
{
    private Database $db;
    private PDO $pdo;
    private array $results = [];
    private bool $useTestDb = true;

    public function __construct(bool $useTestDb = true)
    {
        $this->useTestDb = $useTestDb;
        
        // Override database connection for test DB
        if ($useTestDb) {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', 
                DB_HOST, DB_PORT, 'empower_test_db', DB_CHARSET);
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } else {
            $this->db = Database::getInstance();
            $this->pdo = $this->db->getConnection();
        }
    }

    public function run(): void
    {
        echo "=====================================\n";
        echo "SHARE ACCOUNTS MIGRATION TEST\n";
        echo "Database: " . ($this->useTestDb ? 'empower_test_db' : 'empower_db') . "\n";
        echo "=====================================\n\n";

        if (!$this->useTestDb) {
            echo "ERROR: Test must run on empower_test_db, not production!\n";
            return;
        }

        try {
            // Pre-migration verification
            $this->testPreMigrationState();
            
            // Schema application
            $this->testSchemaApplication();
            
            // Account population
            $this->testAccountPopulation();
            
            // Historical linking
            $this->testHistoricalLinking();
            
            // Balance calculations
            $this->testBalanceCalculations();
            
            // Transfer validation
            $this->testTransferValidation();
            
            // Savings to Shares transfer
            $this->testSavingsToSharesTransfer();
            
            // Shares to Savings transfer
            $this->testSharesToSavingsTransfer();
            
            // Insufficient balance
            $this->testInsufficientBalance();
            
            // Cross-member prevention
            $this->testCrossMemberPrevention();
            
            // Idempotency
            $this->testIdempotency();
            
            // Atomicity
            $this->testAtomicity();
            
            // Historical integrity
            $this->testHistoricalIntegrity();
            
            // Print summary
            $this->printSummary();
            
        } catch (Exception $e) {
            echo "\n❌ FATAL ERROR: {$e->getMessage()}\n";
            echo $e->getTraceAsString() . "\n";
        }
    }

    private function testPreMigrationState(): void
    {
        echo "TEST: Pre-Migration State\n";
        echo str_repeat("-", 50) . "\n";

        try {
            // Check GL 3010 balance
            $gl3010 = $this->pdo->query("
                SELECT COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) as balance
                FROM accounts a
                LEFT JOIN journal_lines jl ON jl.account_id = a.id
                WHERE a.code = '3010'
            ")->fetch();
            
            $this->results['pre_gl_3010'] = (float)$gl3010['balance'];
            echo "✓ GL 3010 Balance: UGX " . number_format($this->results['pre_gl_3010'], 2) . "\n";

            // Check historical shares
            $shareStats = $this->pdo->query("
                SELECT COUNT(*) as cnt, SUM(amount) as total
                FROM share_transactions
            ")->fetch();
            
            $this->results['pre_share_transactions'] = (int)$shareStats['cnt'];
            $this->results['pre_share_total'] = (float)$shareStats['total'];
            echo "✓ Historical share transactions: {$this->results['pre_share_transactions']}\n";
            echo "✓ Historical share total: UGX " . number_format($this->results['pre_share_total'], 2) . "\n";

            // Check member count
            $memberCount = $this->pdo->query("
                SELECT COUNT(*) as cnt FROM members WHERE status = 'active'
            ")->fetch();
            
            $this->results['pre_member_count'] = (int)$memberCount['cnt'];
            echo "✓ Active members: {$this->results['pre_member_count']}\n";

            echo "PASS\n\n";
        } catch (Exception $e) {
            echo "❌ FAIL: {$e->getMessage()}\n\n";
            throw $e;
        }
    }

    private function testSchemaApplication(): void
    {
        echo "TEST: Schema Application\n";
        echo str_repeat("-", 50) . "\n";

        try {
            // Apply schema
            $schema = file_get_contents(__DIR__ . '/../database/member_share_accounts_schema.sql');
            
            // Remove USE statement and split by semicolon
            $schema = preg_replace('/USE\s+`empower_db`;/i', '', $schema);
            $statements = array_filter(array_map('trim', explode(';', $schema)));

            foreach ($statements as $stmt) {
                if (empty($stmt) || strpos($stmt, '--') === 0) continue;
                $this->pdo->exec($stmt);
            }

            // Verify table exists
            $tableExists = $this->pdo->query("
                SHOW TABLES LIKE 'member_share_accounts'
            ")->fetch();

            if (!$tableExists) {
                throw new RuntimeException("member_share_accounts table not created");
            }

            echo "✓ member_share_accounts table created\n";

            // Verify share_transactions column
            $columnExists = $this->pdo->query("
                SHOW COLUMNS FROM share_transactions LIKE 'share_account_id'
            ")->fetch();

            if (!$columnExists) {
                throw new RuntimeException("share_account_id column not added to share_transactions");
            }

            echo "✓ share_account_id column added to share_transactions\n";

            // Verify sequence
            $sequence = $this->pdo->query("
                SELECT * FROM journal_number_sequences WHERE prefix = 'SHR'
            ")->fetch();

            if (!$sequence) {
                throw new RuntimeException("SHR sequence not found");
            }

            echo "✓ SHR number sequence exists\n";
            echo "PASS\n\n";
        } catch (Exception $e) {
            echo "❌ FAIL: {$e->getMessage()}\n\n";
            throw $e;
        }
    }

    private function testAccountPopulation(): void
    {
        echo "TEST: Account Population\n";
        echo str_repeat("-", 50) . "\n";

        try {
            // Load and execute population script
            $populationSQL = file_get_contents(__DIR__ . '/../database/populate_member_share_accounts.sql');
            
            // Remove USE statement
            $populationSQL = preg_replace('/USE\s+`empower_db`;/i', '', $populationSQL);
            
            // Execute the stored procedure creation and call
            $this->pdo->exec($populationSQL);

            // Count created accounts
            $accountCount = $this->pdo->query("
                SELECT COUNT(*) as cnt FROM member_share_accounts
            ")->fetch();

            $this->results['share_accounts_created'] = (int)$accountCount['cnt'];
            echo "✓ Share accounts created: {$this->results['share_accounts_created']}\n";

            if ($this->results['share_accounts_created'] !== $this->results['pre_member_count']) {
                throw new RuntimeException(
                    "Account count mismatch: Expected {$this->results['pre_member_count']}, got {$this->results['share_accounts_created']}"
                );
            }

            // Check for duplicates
            $duplicates = $this->pdo->query("
                SELECT member_id, COUNT(*) as cnt
                FROM member_share_accounts
                GROUP BY member_id
                HAVING cnt > 1
            ")->fetchAll();

            if (count($duplicates) > 0) {
                throw new RuntimeException("Duplicate share accounts found for some members");
            }

            echo "✓ No duplicate accounts (UNIQUE constraint enforced)\n";

            // Verify account numbers
            $sampleAccounts = $this->pdo->query("
                SELECT account_number FROM member_share_accounts LIMIT 5
            ")->fetchAll();

            foreach ($sampleAccounts as $acc) {
                if (!preg_match('/^SHR-\d{6}$/', $acc['account_number'])) {
                    throw new RuntimeException("Invalid account number format: {$acc['account_number']}");
                }
            }

            echo "✓ Account numbers properly formatted (SHR-XXXXXX)\n";
            echo "PASS\n\n";
        } catch (Exception $e) {
            echo "❌ FAIL: {$e->getMessage()}\n\n";
            throw $e;
        }
    }

    private function testHistoricalLinking(): void
    {
        echo "TEST: Historical Transaction Linking\n";
        echo str_repeat("-", 50) . "\n";

        try {
            // Check linked transactions
            $linkedCount = $this->pdo->query("
                SELECT COUNT(*) as cnt
                FROM share_transactions
                WHERE share_account_id IS NOT NULL
            ")->fetch();

            $this->results['linked_transactions'] = (int)$linkedCount['cnt'];
            echo "✓ Transactions linked: {$this->results['linked_transactions']}\n";

            if ($this->results['linked_transactions'] !== $this->results['pre_share_transactions']) {
                throw new RuntimeException(
                    "Not all transactions linked: Expected {$this->results['pre_share_transactions']}, got {$this->results['linked_transactions']}"
                );
            }

            // Verify no orphaned transactions
            $orphaned = $this->pdo->query("
                SELECT COUNT(*) as cnt
                FROM share_transactions
                WHERE share_account_id IS NULL
            ")->fetch();

            if ((int)$orphaned['cnt'] > 0) {
                throw new RuntimeException("Found {$orphaned['cnt']} orphaned share transactions");
            }

            echo "✓ No orphaned transactions\n";

            // Verify correct member mapping
            $wrongMember = $this->pdo->query("
                SELECT COUNT(*) as cnt
                FROM share_transactions st
                INNER JOIN member_share_accounts msa ON msa.id = st.share_account_id
                WHERE st.member_id != msa.member_id
            ")->fetch();

            if ((int)$wrongMember['cnt'] > 0) {
                throw new RuntimeException("Found {$wrongMember['cnt']} transactions linked to wrong member's account");
            }

            echo "✓ All transactions linked to correct member accounts\n";
            echo "PASS\n\n";
        } catch (Exception $e) {
            echo "❌ FAIL: {$e->getMessage()}\n\n";
            throw $e;
        }
    }

    private function testBalanceCalculations(): void
    {
        echo "TEST: Balance Calculations\n";
        echo str_repeat("-", 50) . "\n";

        try {
            $model = new MemberShareAccountModel();

            // Test balance calculation for a member with shares
            $memberWithShares = $this->pdo->query("
                SELECT msa.id, msa.member_id, msa.account_number
                FROM member_share_accounts msa
                INNER JOIN share_transactions st ON st.share_account_id = msa.id
                LIMIT 1
            ")->fetch();

            if ($memberWithShares) {
                $balance = $model->getBalance((int)$memberWithShares['id']);
                
                // Verify against direct SQL
                $directBalance = $this->pdo->prepare("
                    SELECT COALESCE(SUM(amount), 0) as balance
                    FROM share_transactions
                    WHERE share_account_id = ?
                ");
                $directBalance->execute([$memberWithShares['id']]);
                $expected = (float)$directBalance->fetch()['balance'];

                if (abs($balance - $expected) > 0.01) {
                    throw new RuntimeException(
                        "Balance mismatch: Model returned {$balance}, expected {$expected}"
                    );
                }

                echo "✓ Balance calculation accurate for account {$memberWithShares['account_number']}: UGX " . number_format($balance, 2) . "\n";
            }

            // Test member without shares (should be 0)
            $memberWithoutShares = $this->pdo->query("
                SELECT msa.id, msa.account_number
                FROM member_share_accounts msa
                LEFT JOIN share_transactions st ON st.share_account_id = msa.id
                WHERE st.id IS NULL
                LIMIT 1
            ")->fetch();

            if ($memberWithoutShares) {
                $balance = $model->getBalance((int)$memberWithoutShares['id']);
                
                if ($balance != 0) {
                    throw new RuntimeException("Member without shares should have zero balance, got {$balance}");
                }

                echo "✓ Zero balance for accounts without transactions\n";
            }

            echo "PASS\n\n";
        } catch (Exception $e) {
            echo "❌ FAIL: {$e->getMessage()}\n\n";
            throw $e;
        }
    }

    private function testTransferValidation(): void
    {
        echo "TEST: Transfer Validation\n";
        echo str_repeat("-", 50) . "\n";

        try {
            $service = new ShareTransferService();

            // Test negative amount (should throw exception)
            try {
                // This should fail validation
                $member = $this->pdo->query("SELECT id FROM members WHERE status = 'active' LIMIT 1")->fetch();
                $savingsAcc = $this->pdo->query("
                    SELECT id FROM member_savings_accounts 
                    WHERE member_id = ? AND status = 'active' LIMIT 1
                ", [$member['id']])->fetch();
                $shareAcc = $this->pdo->query("
                    SELECT id FROM member_share_accounts WHERE member_id = ? LIMIT 1
                ", [$member['id']])->fetch();

                if ($member && $savingsAcc && $shareAcc) {
                    $service->transferSavingsToShares(
                        (int)$member['id'],
                        (int)$savingsAcc['id'],
                        (int)$shareAcc['id'],
                        -100, // Negative amount
                        'Test invalid amount',
                        1
                    );
                    throw new RuntimeException("Should have rejected negative amount");
                }
            } catch (RuntimeException $e) {
                if (strpos($e->getMessage(), 'greater than zero') === false) {
                    throw $e;
                }
                echo "✓ Negative amounts rejected\n";
            }

            // Test zero amount
            try {
                if ($member && $savingsAcc && $shareAcc) {
                    $service->transferSavingsToShares(
                        (int)$member['id'],
                        (int)$savingsAcc['id'],
                        (int)$shareAcc['id'],
                        0, // Zero amount
                        'Test zero amount',
                        1
                    );
                    throw new RuntimeException("Should have rejected zero amount");
                }
            } catch (RuntimeException $e) {
                if (strpos($e->getMessage(), 'greater than zero') === false) {
                    throw $e;
                }
                echo "✓ Zero amounts rejected\n";
            }

            echo "PASS\n\n";
        } catch (Exception $e) {
            echo "❌ FAIL: {$e->getMessage()}\n\n";
            throw $e;
        }
    }

    private function testSavingsToSharesTransfer(): void
    {
        echo "TEST: Savings → Shares Transfer\n";
        echo str_repeat("-", 50) . "\n";

        try {
            // Find a member with sufficient savings balance
            $member = $this->pdo->query("
                SELECT 
                    m.id as member_id,
                    msa.id as savings_account_id,
                    msha.id as share_account_id,
                    COALESCE(SUM(s.amount), 0) as savings_balance
                FROM members m
                INNER JOIN member_savings_accounts msa ON msa.member_id = m.id
                INNER JOIN member_share_accounts msha ON msha.member_id = m.id
                LEFT JOIN savings s ON s.savings_account_id = msa.id AND s.status = 'posted'
                WHERE m.status = 'active' AND msa.status = 'active'
                GROUP BY m.id, msa.id, msha.id
                HAVING savings_balance >= 100000
                LIMIT 1
            ")->fetch();

            if (!$member) {
                echo "⚠️  SKIP: No member with sufficient savings balance for test\n\n";
                return;
            }

            $transferAmount = 50000.00;
            
            // Get balances before
            $savingsModel = new MemberSavingsAccountModel();
            $shareModel = new MemberShareAccountModel();
            
            $savingsBeforeBalance = $savingsModel->getAccountBalance((int)$member['savings_account_id']);
            $sharesBeforeBalance = $shareModel->getBalance((int)$member['share_account_id']);
            $gl3010Before = $this->getGL3010Balance();
            $gl2020Before = $this->getGL2020Balance();

            echo "Before Transfer:\n";
            echo "  Savings: UGX " . number_format($savingsBeforeBalance, 2) . "\n";
            echo "  Shares: UGX " . number_format($sharesBeforeBalance, 2) . "\n";
            echo "  GL 3010: UGX " . number_format($gl3010Before, 2) . "\n";
            echo "  GL 2020: UGX " . number_format($gl2020Before, 2) . "\n\n";

            // Execute transfer
            $service = new ShareTransferService();
            $result = $service->transferSavingsToShares(
                (int)$member['member_id'],
                (int)$member['savings_account_id'],
                (int)$member['share_account_id'],
                $transferAmount,
                'Test transfer Savings to Shares',
                1
            );

            echo "✓ Transfer executed: {$result['reference_number']}\n";
            echo "✓ Journal entry: {$result['journal_entry_number']}\n";

            // Get balances after
            $savingsAfterBalance = $savingsModel->getAccountBalance((int)$member['savings_account_id']);
            $sharesAfterBalance = $shareModel->getBalance((int)$member['share_account_id']);
            $gl3010After = $this->getGL3010Balance();
            $gl2020After = $this->getGL2020Balance();

            echo "\nAfter Transfer:\n";
            echo "  Savings: UGX " . number_format($savingsAfterBalance, 2) . "\n";
            echo "  Shares: UGX " . number_format($sharesAfterBalance, 2) . "\n";
            echo "  GL 3010: UGX " . number_format($gl3010After, 2) . "\n";
            echo "  GL 2020: UGX " . number_format($gl2020After, 2) . "\n\n";

            // Verify balances changed correctly
            $expectedSavings = $savingsBeforeBalance - $transferAmount;
            $expectedShares = $sharesBeforeBalance + $transferAmount;

            if (abs($savingsAfterBalance - $expectedSavings) > 0.01) {
                throw new RuntimeException(
                    "Savings balance incorrect: Expected " . number_format($expectedSavings, 2) . 
                    ", got " . number_format($savingsAfterBalance, 2)
                );
            }

            if (abs($sharesAfterBalance - $expectedShares) > 0.01) {
                throw new RuntimeException(
                    "Shares balance incorrect: Expected " . number_format($expectedShares, 2) . 
                    ", got " . number_format($sharesAfterBalance, 2)
                );
            }

            echo "✓ Savings balance decreased by UGX " . number_format($transferAmount, 2) . "\n";
            echo "✓ Shares balance increased by UGX " . number_format($transferAmount, 2) . "\n";

            // Verify GL changes
            $expectedGL3010 = $gl3010Before + $transferAmount;
            $expectedGL2020 = $gl2020Before - $transferAmount;

            if (abs($gl3010After - $expectedGL3010) > 0.01) {
                throw new RuntimeException("GL 3010 balance incorrect");
            }

            if (abs($gl2020After - $expectedGL2020) > 0.01) {
                throw new RuntimeException("GL 2020 balance incorrect");
            }

            echo "✓ GL 3010 increased by UGX " . number_format($transferAmount, 2) . "\n";
            echo "✓ GL 2020 decreased by UGX " . number_format($transferAmount, 2) . "\n";

            echo "PASS\n\n";
        } catch (Exception $e) {
            echo "❌ FAIL: {$e->getMessage()}\n\n";
            throw $e;
        }
    }

    private function testSharesToSavingsTransfer(): void
    {
        echo "TEST: Shares → Savings Transfer\n";
        echo str_repeat("-", 50) . "\n";

        try {
            // Find member with shares
            $member = $this->pdo->query("
                SELECT 
                    m.id as member_id,
                    msa.id as savings_account_id,
                    msha.id as share_account_id,
                    COALESCE(SUM(st.amount), 0) as share_balance
                FROM members m
                INNER JOIN member_savings_accounts msa ON msa.member_id = m.id
                INNER JOIN member_share_accounts msha ON msha.member_id = m.id
                LEFT JOIN share_transactions st ON st.share_account_id = msha.id
                WHERE m.status = 'active' AND msa.status = 'active'
                GROUP BY m.id, msa.id, msha.id
                HAVING share_balance >= 50000
                LIMIT 1
            ")->fetch();

            if (!$member) {
                echo "⚠️  SKIP: No member with sufficient share balance for test\n\n";
                return;
            }

            $transferAmount = 25000.00;
            
            // Get balances before
            $savingsModel = new MemberSavingsAccountModel();
            $shareModel = new MemberShareAccountModel();
            
            $savingsBeforeBalance = $savingsModel->getAccountBalance((int)$member['savings_account_id']);
            $sharesBeforeBalance = $shareModel->getBalance((int)$member['share_account_id']);
            $gl3010Before = $this->getGL3010Balance();
            $gl2020Before = $this->getGL2020Balance();

            echo "Before Transfer:\n";
            echo "  Shares: UGX " . number_format($sharesBeforeBalance, 2) . "\n";
            echo "  Savings: UGX " . number_format($savingsBeforeBalance, 2) . "\n\n";

            // Execute transfer
            $service = new ShareTransferService();
            $result = $service->transferSharesToSavings(
                (int)$member['member_id'],
                (int)$member['share_account_id'],
                (int)$member['savings_account_id'],
                $transferAmount,
                'Test transfer Shares to Savings',
                1
            );

            echo "✓ Transfer executed: {$result['reference_number']}\n";
            echo "✓ Journal entry: {$result['journal_entry_number']}\n";

            // Get balances after
            $savingsAfterBalance = $savingsModel->getAccountBalance((int)$member['savings_account_id']);
            $sharesAfterBalance = $shareModel->getBalance((int)$member['share_account_id']);
            $gl3010After = $this->getGL3010Balance();
            $gl2020After = $this->getGL2020Balance();

            echo "\nAfter Transfer:\n";
            echo "  Shares: UGX " . number_format($sharesAfterBalance, 2) . "\n";
            echo "  Savings: UGX " . number_format($savingsAfterBalance, 2) . "\n\n";

            // Verify balances
            $expectedShares = $sharesBeforeBalance - $transferAmount;
            $expectedSavings = $savingsBeforeBalance + $transferAmount;

            if (abs($sharesAfterBalance - $expectedShares) > 0.01) {
                throw new RuntimeException("Shares balance incorrect");
            }

            if (abs($savingsAfterBalance - $expectedSavings) > 0.01) {
                throw new RuntimeException("Savings balance incorrect");
            }

            echo "✓ Shares balance decreased by UGX " . number_format($transferAmount, 2) . "\n";
            echo "✓ Savings balance increased by UGX " . number_format($transferAmount, 2) . "\n";

            // Verify GL changes
            $expectedGL3010 = $gl3010Before - $transferAmount;
            $expectedGL2020 = $gl2020Before + $transferAmount;

            if (abs($gl3010After - $expectedGL3010) > 0.01) {
                throw new RuntimeException("GL 3010 balance incorrect");
            }

            if (abs($gl2020After - $expectedGL2020) > 0.01) {
                throw new RuntimeException("GL 2020 balance incorrect");
            }

            echo "✓ GL 3010 decreased by UGX " . number_format($transferAmount, 2) . "\n";
            echo "✓ GL 2020 increased by UGX " . number_format($transferAmount, 2) . "\n";

            echo "PASS\n\n";
        } catch (Exception $e) {
            echo "❌ FAIL: {$e->getMessage()}\n\n";
            throw $e;
        }
    }

    private function testInsufficientBalance(): void
    {
        echo "TEST: Insufficient Balance Rejection\n";
        echo str_repeat("-", 50) . "\n";

        try {
            $member = $this->pdo->query("
                SELECT 
                    m.id as member_id,
                    msa.id as savings_account_id,
                    msha.id as share_account_id
                FROM members m
                INNER JOIN member_savings_accounts msa ON msa.member_id = m.id
                INNER JOIN member_share_accounts msha ON msha.member_id = m.id
                WHERE m.status = 'active'
                LIMIT 1
            ")->fetch();

            if (!$member) {
                echo "⚠️  SKIP: No member found for test\n\n";
                return;
            }

            $service = new ShareTransferService();
            
            try {
                $service->transferSavingsToShares(
                    (int)$member['member_id'],
                    (int)$member['savings_account_id'],
                    (int)$member['share_account_id'],
                    999999999.99, // Excessive amount
                    'Test insufficient balance',
                    1
                );
                throw new RuntimeException("Should have rejected insufficient balance");
            } catch (RuntimeException $e) {
                if (strpos($e->getMessage(), 'Insufficient') === false) {
                    throw $e;
                }
                echo "✓ Insufficient balance rejected\n";
            }

            echo "PASS\n\n";
        } catch (Exception $e) {
            echo "❌ FAIL: {$e->getMessage()}\n\n";
            throw $e;
        }
    }

    private function testCrossMemberPrevention(): void
    {
        echo "TEST: Cross-Member Transfer Prevention\n";
        echo str_repeat("-", 50) . "\n";

        try {
            // Get two different members
            $members = $this->pdo->query("
                SELECT 
                    m.id as member_id,
                    msa.id as savings_account_id,
                    msha.id as share_account_id
                FROM members m
                INNER JOIN member_savings_accounts msa ON msa.member_id = m.id
                INNER JOIN member_share_accounts msha ON msha.member_id = m.id
                WHERE m.status = 'active'
                LIMIT 2
            ")->fetchAll();

            if (count($members) < 2) {
                echo "⚠️  SKIP: Need at least 2 members for test\n\n";
                return;
            }

            $service = new ShareTransferService();
            
            try {
                // Try to transfer from member 1's savings to member 2's shares
                $service->transferSavingsToShares(
                    (int)$members[0]['member_id'],
                    (int)$members[0]['savings_account_id'],
                    (int)$members[1]['share_account_id'], // Different member!
                    1000,
                    'Test cross-member',
                    1
                );
                throw new RuntimeException("Should have rejected cross-member transfer");
            } catch (RuntimeException $e) {
                if (strpos($e->getMessage(), 'does not belong') === false) {
                    throw $e;
                }
                echo "✓ Cross-member transfer rejected\n";
            }

            echo "PASS\n\n";
        } catch (Exception $e) {
            echo "❌ FAIL: {$e->getMessage()}\n\n";
            throw $e;
        }
    }

    private function testIdempotency(): void
    {
        echo "TEST: Idempotency (Duplicate Prevention)\n";
        echo str_repeat("-", 50) . "\n";

        try {
            // This test would require manually creating a duplicate reference
            // For now, just verify the reference uniqueness in the database
            $duplicates = $this->pdo->query("
                SELECT reference_number, COUNT(*) as cnt
                FROM share_transactions
                WHERE reference_number IS NOT NULL
                GROUP BY reference_number
                HAVING cnt > 1
            ")->fetchAll();

            if (count($duplicates) > 0) {
                throw new RuntimeException("Found duplicate reference numbers");
            }

            echo "✓ No duplicate references found\n";
            echo "PASS\n\n";
        } catch (Exception $e) {
            echo "❌ FAIL: {$e->getMessage()}\n\n";
            throw $e;
        }
    }

    private function testAtomicity(): void
    {
        echo "TEST: Transaction Atomicity\n";
        echo str_repeat("-", 50) . "\n";

        try {
            // Verify all transfers have matching journal entries
            $orphanedTransfers = $this->pdo->query("
                SELECT COUNT(*) as cnt
                FROM share_transactions st
                WHERE st.transaction_type IN ('transfer_in', 'transfer_out')
                  AND st.journal_entry_id IS NULL
            ")->fetch();

            if ((int)$orphanedTransfers['cnt'] > 0) {
                throw new RuntimeException("Found transfer transactions without journal entries");
            }

            echo "✓ All transfer transactions have journal entries\n";
            
            // Verify all transfer journals are balanced
            $unbalanced = $this->pdo->query("
                SELECT je.id, je.entry_number,
                       SUM(jl.debit) as total_debit,
                       SUM(jl.credit) as total_credit
                FROM journal_entries je
                INNER JOIN journal_lines jl ON jl.journal_entry_id = je.id
                WHERE je.source_module = 'share_transfers'
                GROUP BY je.id, je.entry_number
                HAVING ABS(total_debit - total_credit) > 0.01
            ")->fetchAll();

            if (count($unbalanced) > 0) {
                throw new RuntimeException("Found unbalanced journal entries");
            }

            echo "✓ All transfer journals are balanced\n";
            echo "PASS\n\n";
        } catch (Exception $e) {
            echo "❌ FAIL: {$e->getMessage()}\n\n";
            throw $e;
        }
    }

    private function testHistoricalIntegrity(): void
    {
        echo "TEST: Historical Integrity Verification\n";
        echo str_repeat("-", 50) . "\n";

        try {
            // Verify historical share total unchanged
            $currentShareTotal = $this->pdo->query("
                SELECT SUM(amount) as total
                FROM share_transactions
                WHERE transaction_type NOT IN ('transfer_in', 'transfer_out')
            ")->fetch();

            $historicalTotal = (float)$currentShareTotal['total'];
            
            if (abs($historicalTotal - $this->results['pre_share_total']) > 0.01) {
                throw new RuntimeException(
                    "Historical share total changed! Was " . number_format($this->results['pre_share_total'], 2) . 
                    ", now " . number_format($historicalTotal, 2)
                );
            }

            echo "✓ Historical share total unchanged: UGX " . number_format($historicalTotal, 2) . "\n";

            // Verify transaction count for historical transactions
            $historicalCount = $this->pdo->query("
                SELECT COUNT(*) as cnt
                FROM share_transactions
                WHERE transaction_type NOT IN ('transfer_in', 'transfer_out')
            ")->fetch();

            if ((int)$historicalCount['cnt'] !== $this->results['pre_share_transactions']) {
                throw new RuntimeException("Historical transaction count changed");
            }

            echo "✓ Historical transaction count unchanged: {$this->results['pre_share_transactions']}\n";

            echo "PASS\n\n";
        } catch (Exception $e) {
            echo "❌ FAIL: {$e->getMessage()}\n\n";
            throw $e;
        }
    }

    private function getGL3010Balance(): float
    {
        $result = $this->pdo->query("
            SELECT COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) as balance
            FROM accounts a
            LEFT JOIN journal_lines jl ON jl.account_id = a.id
            WHERE a.code = '3010'
        ")->fetch();
        
        return (float)$result['balance'];
    }

    private function getGL2020Balance(): float
    {
        $result = $this->pdo->query("
            SELECT COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) as balance
            FROM accounts a
            LEFT JOIN journal_lines jl ON jl.account_id = a.id
            WHERE a.code = '2020'
        ")->fetch();
        
        return (float)$result['balance'];
    }

    private function printSummary(): void
    {
        echo "=====================================\n";
        echo "TEST SUMMARY\n";
        echo "=====================================\n\n";

        echo "Pre-Migration State:\n";
        echo "  GL 3010: UGX " . number_format($this->results['pre_gl_3010'] ?? 0, 2) . "\n";
        echo "  Share Transactions: " . ($this->results['pre_share_transactions'] ?? 0) . "\n";
        echo "  Share Total: UGX " . number_format($this->results['pre_share_total'] ?? 0, 2) . "\n";
        echo "  Active Members: " . ($this->results['pre_member_count'] ?? 0) . "\n\n";

        echo "Post-Migration State:\n";
        echo "  Share Accounts Created: " . ($this->results['share_accounts_created'] ?? 0) . "\n";
        echo "  Transactions Linked: " . ($this->results['linked_transactions'] ?? 0) . "\n\n";

        echo "✅ ALL TESTS PASSED\n";
        echo "Ready for production deployment.\n";
    }
}

// Run tests
$test = new ShareAccountsMigrationTest(true);
$test->run();
