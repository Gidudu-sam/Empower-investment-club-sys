<?php

/**
 * MemberShareAccountModel
 * 
 * Manages member share accounts - one share account per member.
 * 
 * Business Rule: Each member has exactly ONE share account.
 * Balance is always derived from share_transactions, never stored.
 */
class MemberShareAccountModel
{
    private Database $db;
    private PDO $pdo;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getConnection();
    }

    /**
     * Find a share account by ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                msa.*,
                m.member_number,
                m.first_name,
                m.last_name,
                m.phone,
                m.email,
                m.status as member_status
            FROM member_share_accounts msa
            INNER JOIN members m ON m.id = msa.member_id
            WHERE msa.id = ?
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find a share account by member ID
     */
    public function findByMemberId(int $memberId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                msa.*,
                m.member_number,
                m.first_name,
                m.last_name,
                m.phone,
                m.email,
                m.status as member_status
            FROM member_share_accounts msa
            INNER JOIN members m ON m.id = msa.member_id
            WHERE msa.member_id = ?
        ");
        $stmt->execute([$memberId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find a share account by account number
     */
    public function findByAccountNumber(string $accountNumber): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                msa.*,
                m.member_number,
                m.first_name,
                m.last_name,
                m.phone,
                m.email,
                m.status as member_status
            FROM member_share_accounts msa
            INNER JOIN members m ON m.id = msa.member_id
            WHERE msa.account_number = ?
        ");
        $stmt->execute([$accountNumber]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get the balance for a share account
     * Balance = sum of all share transaction amounts (credit-based)
     */
    public function getBalance(int $shareAccountId): float
    {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as balance
            FROM share_transactions
            WHERE share_account_id = ?
        ");
        $stmt->execute([$shareAccountId]);
        $result = $stmt->fetch();
        return (float)($result['balance'] ?? 0);
    }

    /**
     * Get the balance by member ID
     */
    public function getBalanceByMemberId(int $memberId): float
    {
        $account = $this->findByMemberId($memberId);
        if (!$account) {
            return 0.0;
        }
        return $this->getBalance((int)$account['id']);
    }

    /**
     * Get all share transactions for an account
     */
    public function getTransactions(int $shareAccountId, ?int $limit = null, int $offset = 0): array
    {
        $sql = "
            SELECT 
                st.*,
                u.full_name as processed_by_name,
                je.entry_number as journal_entry_number
            FROM share_transactions st
            LEFT JOIN users u ON u.id = st.processed_by
            LEFT JOIN journal_entries je ON je.id = st.journal_entry_id
            WHERE st.share_account_id = ?
            ORDER BY st.transaction_date DESC, st.id DESC
        ";
        
        if ($limit !== null) {
            $sql .= " LIMIT ? OFFSET ?";
        }
        
        $stmt = $this->pdo->prepare($sql);
        
        if ($limit !== null) {
            $stmt->execute([$shareAccountId, $limit, $offset]);
        } else {
            $stmt->execute([$shareAccountId]);
        }
        
        return $stmt->fetchAll();
    }

    /**
     * Get transaction count for an account
     */
    public function getTransactionCount(int $shareAccountId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as cnt
            FROM share_transactions
            WHERE share_account_id = ?
        ");
        $stmt->execute([$shareAccountId]);
        $result = $stmt->fetch();
        return (int)($result['cnt'] ?? 0);
    }

    /**
     * Create a new share account for a member
     * 
     * @param array $data Must contain: member_id, opened_date, created_by
     * @return int The new account ID
     * @throws RuntimeException if member already has a share account
     */
    public function create(array $data): int
    {
        // Check if member already has a share account
        $existing = $this->findByMemberId((int)$data['member_id']);
        if ($existing) {
            throw new RuntimeException("Member already has a share account: {$existing['account_number']}");
        }

        // Generate account number
        $accountNumber = $this->generateAccountNumber();

        $stmt = $this->pdo->prepare("
            INSERT INTO member_share_accounts (
                member_id,
                account_number,
                status,
                opened_date,
                created_by,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $stmt->execute([
            $data['member_id'],
            $accountNumber,
            $data['status'] ?? 'active',
            $data['opened_date'],
            $data['created_by']
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Generate next account number using SHR prefix
     * Thread-safe with row locking
     */
    private function generateAccountNumber(): string
    {
        // Lock the row for update
        $stmt = $this->pdo->prepare("
            SELECT last_number 
            FROM journal_number_sequences 
            WHERE prefix = 'SHR' 
            FOR UPDATE
        ");
        $stmt->execute();
        $row = $stmt->fetch();
        
        if (!$row) {
            throw new RuntimeException("SHR sequence not found in journal_number_sequences");
        }

        $nextNumber = (int)$row['last_number'] + 1;

        // Update sequence
        $stmt = $this->pdo->prepare("
            UPDATE journal_number_sequences 
            SET last_number = ? 
            WHERE prefix = 'SHR'
        ");
        $stmt->execute([$nextNumber]);

        return sprintf('SHR-%06d', $nextNumber);
    }

    /**
     * Update share account status
     */
    public function updateStatus(int $id, string $status, ?string $closedDate = null): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE member_share_accounts 
            SET status = ?,
                closed_date = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$status, $closedDate, $id]);
    }

    /**
     * Get all share accounts with balances
     */
    public function getAllWithBalances(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                msa.*,
                m.member_number,
                m.first_name,
                m.last_name,
                m.phone,
                m.status as member_status,
                COALESCE(SUM(st.amount), 0) as balance,
                COUNT(st.id) as transaction_count
            FROM member_share_accounts msa
            INNER JOIN members m ON m.id = msa.member_id
            LEFT JOIN share_transactions st ON st.share_account_id = msa.id
            GROUP BY msa.id
            ORDER BY msa.account_number
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Get total count of share accounts
     */
    public function getTotalCount(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) as cnt FROM member_share_accounts");
        $result = $stmt->fetch();
        return (int)($result['cnt'] ?? 0);
    }

    /**
     * Get account with full details including balance
     */
    public function getAccountDetails(int $id): ?array
    {
        $account = $this->findById($id);
        if (!$account) {
            return null;
        }

        $account['balance'] = $this->getBalance($id);
        $account['transaction_count'] = $this->getTransactionCount($id);

        return $account;
    }

    /**
     * Check if member has a share account
     */
    public function memberHasAccount(int $memberId): bool
    {
        $account = $this->findByMemberId($memberId);
        return $account !== null;
    }
}
