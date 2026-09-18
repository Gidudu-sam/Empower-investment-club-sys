<?php

/**
 * ShareTransferService
 * 
 * Handles internal transfers between a member's Savings and Share accounts.
 * 
 * Supported transfer types:
 * 1. Savings → Shares  (DR 2020 Members' Savings / CR 3010 Shares)
 * 2. Shares → Savings  (DR 3010 Shares / CR 2020 Members' Savings)
 * 
 * IMPORTANT: These are internal reclassifications, NOT external cash movements.
 * No Cash, Bank, or Mobile Money accounts are affected.
 * 
 * All transfers are:
 * - Atomic (all-or-nothing)
 * - Idempotent (duplicate requests rejected)
 * - Same-member only (cannot transfer between members)
 * - Balance-validated (sufficient funds required)
 * - Fully journaled (GL + subledger)
 * - Audit-logged
 */
class ShareTransferService
{
    private Database $db;
    private PDO $pdo;
    private JournalService $journalService;
    private MemberShareAccountModel $shareAccountModel;
    private AccountModel $accountModel;

    // GL Account IDs (from investigation: 2020=id:17, 3010=id:24)
    private const SAVINGS_LIABILITY_ACCOUNT_ID = 17;  // 2020 Members' Savings
    private const SHARES_CAPITAL_ACCOUNT_ID = 24;     // 3010 Shares

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getConnection();
        $this->journalService = new JournalService();
        $this->shareAccountModel = new MemberShareAccountModel();
        $this->accountModel = new AccountModel();
    }

    /**
     * Transfer from Savings to Shares
     * 
     * @param int $memberId Member performing the transfer
     * @param int $savingsAccountId Source savings account
     * @param int $shareAccountId Destination share account
     * @param float $amount Amount to transfer
     * @param string $narration Description/reason
     * @param int $userId User executing the transfer
     * @param string $referenceNumber Optional unique reference
     * @return array Transfer result with reference and journal entry
     * @throws RuntimeException on validation failure or processing error
     */
    public function transferSavingsToShares(
        int $memberId,
        int $savingsAccountId,
        int $shareAccountId,
        float $amount,
        string $narration,
        int $userId,
        ?string $referenceNumber = null
    ): array {
        // Generate reference if not provided
        if ($referenceNumber === null) {
            $referenceNumber = $this->generateTransferReference('STS');
        }

        // Validate the transfer
        $this->validateTransfer($memberId, $savingsAccountId, $shareAccountId, $amount, 'savings_to_shares');

        // Check for duplicate reference (idempotency)
        if ($this->referenceExists($referenceNumber)) {
            throw new RuntimeException("Transfer reference already exists: {$referenceNumber}");
        }

        // Start transaction
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $transferDate = date('Y-m-d');

            // 1. Create savings debit transaction
            $savingsStmt = $this->pdo->prepare("
                INSERT INTO savings (
                    member_id,
                    savings_account_id,
                    transaction_type,
                    transaction_date,
                    amount,
                    reference_number,
                    narration,
                    status,
                    recorded_by,
                    created_at
                ) VALUES (?, ?, 'transfer_out', ?, ?, ?, ?, 'posted', ?, NOW())
            ");
            $savingsStmt->execute([
                $memberId,
                $savingsAccountId,
                $transferDate,
                $amount,
                $referenceNumber,
                $narration,
                $userId
            ]);
            $savingsTransactionId = (int)$this->pdo->lastInsertId();

            // 2. Post journal entry (DR 2020 / CR 3010)
            $journalResult = $this->journalService->post([
                'entry_date' => $transferDate,
                'description' => "Share Transfer: Savings to Shares - {$narration}",
                'source_module' => 'share_transfers',
                'source_reference_type' => 'savings_to_shares',
                'source_reference_id' => $savingsTransactionId,
                'created_by' => $userId,
                'lines' => [
                    [
                        'account_id' => self::SAVINGS_LIABILITY_ACCOUNT_ID,
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => "Transfer to Shares - {$referenceNumber}"
                    ],
                    [
                        'account_id' => self::SHARES_CAPITAL_ACCOUNT_ID,
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => "Transfer from Savings - {$referenceNumber}"
                    ]
                ]
            ]);

            // 3. Create share credit transaction
            $shareStmt = $this->pdo->prepare("
                INSERT INTO share_transactions (
                    member_id,
                    share_account_id,
                    transaction_type,
                    transaction_date,
                    quantity,
                    share_value,
                    amount,
                    reference_number,
                    source_reference_type,
                    source_reference_id,
                    journal_entry_id,
                    processed_by,
                    created_at
                ) VALUES (?, ?, 'transfer_in', ?, ?, ?, ?, ?, 'savings_transfer', ?, ?, ?, NOW())
            ");
            
            // Get current share value from settings
            $shareValue = $this->getCurrentShareValue();
            $quantity = $amount / $shareValue;
            
            $shareStmt->execute([
                $memberId,
                $shareAccountId,
                $transferDate,
                $quantity,
                $shareValue,
                $amount,
                $referenceNumber,
                $savingsTransactionId,
                $journalResult['id'],
                $userId
            ]);

            // 4. Log audit trail
            $this->logTransfer([
                'type' => 'savings_to_shares',
                'member_id' => $memberId,
                'savings_account_id' => $savingsAccountId,
                'share_account_id' => $shareAccountId,
                'amount' => $amount,
                'reference_number' => $referenceNumber,
                'journal_entry_id' => $journalResult['id'],
                'user_id' => $userId,
                'narration' => $narration
            ]);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return [
                'success' => true,
                'reference_number' => $referenceNumber,
                'journal_entry_number' => $journalResult['entry_number'],
                'journal_entry_id' => $journalResult['id'],
                'amount' => $amount,
                'type' => 'savings_to_shares'
            ];

        } catch (Exception $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Transfer failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Transfer from Shares to Savings
     * 
     * @param int $memberId Member performing the transfer
     * @param int $shareAccountId Source share account
     * @param int $savingsAccountId Destination savings account
     * @param float $amount Amount to transfer
     * @param string $narration Description/reason
     * @param int $userId User executing the transfer
     * @param string $referenceNumber Optional unique reference
     * @return array Transfer result with reference and journal entry
     * @throws RuntimeException on validation failure or processing error
     */
    public function transferSharesToSavings(
        int $memberId,
        int $shareAccountId,
        int $savingsAccountId,
        float $amount,
        string $narration,
        int $userId,
        ?string $referenceNumber = null
    ): array {
        // Generate reference if not provided
        if ($referenceNumber === null) {
            $referenceNumber = $this->generateTransferReference('SST');
        }

        // Validate the transfer
        $this->validateTransfer($memberId, $savingsAccountId, $shareAccountId, $amount, 'shares_to_savings');

        // Check for duplicate reference (idempotency)
        if ($this->referenceExists($referenceNumber)) {
            throw new RuntimeException("Transfer reference already exists: {$referenceNumber}");
        }

        // Start transaction
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $transferDate = date('Y-m-d');

            // 1. Create share debit transaction (negative amount)
            $shareStmt = $this->pdo->prepare("
                INSERT INTO share_transactions (
                    member_id,
                    share_account_id,
                    transaction_type,
                    transaction_date,
                    quantity,
                    share_value,
                    amount,
                    reference_number,
                    source_reference_type,
                    journal_entry_id,
                    processed_by,
                    created_at
                ) VALUES (?, ?, 'transfer_out', ?, ?, ?, ?, ?, 'savings_transfer', ?, ?, NOW())
            ");
            
            // Get current share value
            $shareValue = $this->getCurrentShareValue();
            $quantity = -($amount / $shareValue);  // Negative for transfer out
            
            // First create a placeholder, then update with journal_entry_id
            $shareStmt->execute([
                $memberId,
                $shareAccountId,
                $transferDate,
                $quantity,
                $shareValue,
                -$amount,  // Negative for transfer out
                $referenceNumber,
                'savings_transfer',
                null,  // Will update after journal created
                $userId
            ]);
            $shareTransactionId = (int)$this->pdo->lastInsertId();

            // 2. Post journal entry (DR 3010 / CR 2020)
            $journalResult = $this->journalService->post([
                'entry_date' => $transferDate,
                'description' => "Share Transfer: Shares to Savings - {$narration}",
                'source_module' => 'share_transfers',
                'source_reference_type' => 'shares_to_savings',
                'source_reference_id' => $shareTransactionId,
                'created_by' => $userId,
                'lines' => [
                    [
                        'account_id' => self::SHARES_CAPITAL_ACCOUNT_ID,
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => "Transfer to Savings - {$referenceNumber}"
                    ],
                    [
                        'account_id' => self::SAVINGS_LIABILITY_ACCOUNT_ID,
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => "Transfer from Shares - {$referenceNumber}"
                    ]
                ]
            ]);

            // Update share transaction with journal_entry_id
            $updateStmt = $this->pdo->prepare("
                UPDATE share_transactions 
                SET journal_entry_id = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$journalResult['id'], $shareTransactionId]);

            // 3. Create savings credit transaction
            $savingsStmt = $this->pdo->prepare("
                INSERT INTO savings (
                    member_id,
                    savings_account_id,
                    transaction_type,
                    transaction_date,
                    amount,
                    reference_number,
                    narration,
                    status,
                    recorded_by,
                    created_at
                ) VALUES (?, ?, 'transfer_in', ?, ?, ?, ?, 'posted', ?, NOW())
            ");
            $savingsStmt->execute([
                $memberId,
                $savingsAccountId,
                $transferDate,
                $amount,
                $referenceNumber,
                $narration,
                $userId
            ]);

            // 4. Log audit trail
            $this->logTransfer([
                'type' => 'shares_to_savings',
                'member_id' => $memberId,
                'savings_account_id' => $savingsAccountId,
                'share_account_id' => $shareAccountId,
                'amount' => $amount,
                'reference_number' => $referenceNumber,
                'journal_entry_id' => $journalResult['id'],
                'user_id' => $userId,
                'narration' => $narration
            ]);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return [
                'success' => true,
                'reference_number' => $referenceNumber,
                'journal_entry_number' => $journalResult['entry_number'],
                'journal_entry_id' => $journalResult['id'],
                'amount' => $amount,
                'type' => 'shares_to_savings'
            ];

        } catch (Exception $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Transfer failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate a transfer request
     */
    private function validateTransfer(
        int $memberId,
        int $savingsAccountId,
        int $shareAccountId,
        float $amount,
        string $direction
    ): void {
        // Validate amount
        if ($amount <= 0) {
            throw new RuntimeException("Transfer amount must be greater than zero");
        }

        if ($amount > 999999999.99) {
            throw new RuntimeException("Transfer amount exceeds maximum allowed");
        }

        // Validate savings account belongs to member
        $savingsStmt = $this->pdo->prepare("
            SELECT member_id, status 
            FROM member_savings_accounts 
            WHERE id = ?
        ");
        $savingsStmt->execute([$savingsAccountId]);
        $savingsAccount = $savingsStmt->fetch();

        if (!$savingsAccount) {
            throw new RuntimeException("Savings account not found");
        }

        if ((int)$savingsAccount['member_id'] !== $memberId) {
            throw new RuntimeException("Savings account does not belong to this member");
        }

        if ($savingsAccount['status'] !== 'active') {
            throw new RuntimeException("Savings account is not active");
        }

        // Validate share account belongs to member
        $shareAccount = $this->shareAccountModel->findById($shareAccountId);
        if (!$shareAccount) {
            throw new RuntimeException("Share account not found");
        }

        if ((int)$shareAccount['member_id'] !== $memberId) {
            throw new RuntimeException("Share account does not belong to this member");
        }

        if ($shareAccount['status'] !== 'active') {
            throw new RuntimeException("Share account is not active");
        }

        // Validate sufficient balance
        if ($direction === 'savings_to_shares') {
            $savingsBalance = $this->getSavingsBalance($savingsAccountId);
            if ($savingsBalance < $amount) {
                throw new RuntimeException(sprintf(
                    "Insufficient savings balance. Available: UGX %s, Required: UGX %s",
                    number_format($savingsBalance, 2),
                    number_format($amount, 2)
                ));
            }
        } else {
            $shareBalance = $this->shareAccountModel->getBalance($shareAccountId);
            if ($shareBalance < $amount) {
                throw new RuntimeException(sprintf(
                    "Insufficient share balance. Available: UGX %s, Required: UGX %s",
                    number_format($shareBalance, 2),
                    number_format($amount, 2)
                ));
            }
        }
    }

    /**
     * Get savings account balance
     */
    private function getSavingsBalance(int $savingsAccountId): float
    {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as balance
            FROM savings
            WHERE savings_account_id = ?
            AND status = 'posted'
        ");
        $stmt->execute([$savingsAccountId]);
        $result = $stmt->fetch();
        return (float)($result['balance'] ?? 0);
    }

    /**
     * Get current share value from settings
     */
    private function getCurrentShareValue(): float
    {
        $stmt = $this->pdo->query("
            SELECT setting_value 
            FROM settings 
            WHERE setting_key = 'share_value' 
            LIMIT 1
        ");
        $result = $stmt->fetch();
        
        if (!$result) {
            throw new RuntimeException("Share value not configured in settings");
        }

        return (float)$result['setting_value'];
    }

    /**
     * Generate a unique transfer reference
     */
    private function generateTransferReference(string $prefix): string
    {
        // Lock sequence row
        $stmt = $this->pdo->prepare("
            SELECT last_number 
            FROM journal_number_sequences 
            WHERE prefix = ? 
            FOR UPDATE
        ");
        $stmt->execute([$prefix]);
        $row = $stmt->fetch();

        if (!$row) {
            // Create sequence if it doesn't exist
            $insertStmt = $this->pdo->prepare("
                INSERT INTO journal_number_sequences (prefix, last_number) 
                VALUES (?, 0)
            ");
            $insertStmt->execute([$prefix]);
            $nextNumber = 1;
        } else {
            $nextNumber = (int)$row['last_number'] + 1;
        }

        // Update sequence
        $updateStmt = $this->pdo->prepare("
            UPDATE journal_number_sequences 
            SET last_number = ? 
            WHERE prefix = ?
        ");
        $updateStmt->execute([$nextNumber, $prefix]);

        return sprintf('%s-%06d', $prefix, $nextNumber);
    }

    /**
     * Check if a reference number already exists
     */
    private function referenceExists(string $referenceNumber): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as cnt 
            FROM share_transactions 
            WHERE reference_number = ?
        ");
        $stmt->execute([$referenceNumber]);
        $result = $stmt->fetch();
        
        return (int)($result['cnt'] ?? 0) > 0;
    }

    /**
     * Log transfer to activity log
     */
    private function logTransfer(array $data): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO activity_logs (
                user_id,
                action,
                description,
                ip_address,
                created_at
            ) VALUES (?, ?, ?, ?, NOW())
        ");

        $description = sprintf(
            "Share Transfer: %s - Member ID: %d, Amount: UGX %s, Reference: %s, Journal: JE%s - %s",
            $data['type'] === 'savings_to_shares' ? 'Savings to Shares' : 'Shares to Savings',
            $data['member_id'],
            number_format($data['amount'], 2),
            $data['reference_number'],
            str_pad($data['journal_entry_id'], 5, '0', STR_PAD_LEFT),
            $data['narration']
        );

        $stmt->execute([
            $data['user_id'],
            'share_transfer',
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }
}
