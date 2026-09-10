<?php
/**
 * AccountModel — Chart of Accounts
 */
class AccountModel extends Model
{
    protected string $table      = 'accounts';
    protected string $primaryKey = 'id';

    public function findByCode(string $code): array|false
    {
        return $this->findWhere(['code' => $code]);
    }

    public function findActive(int $id): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM `accounts` WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function activeAccounts(): array
    {
        return $this->db->query("SELECT * FROM `accounts` WHERE is_active = 1 ORDER BY code")->fetchAll();
    }

    public function byType(string $type): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `accounts` WHERE type = ? ORDER BY code");
        $stmt->execute([$type]);
        return $stmt->fetchAll();
    }

    /** Active accounts of a given type only — e.g. bounding a selector to active income accounts. */
    public function activeByType(string $type): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `accounts` WHERE type = ? AND is_active = 1 ORDER BY code");
        $stmt->execute([$type]);
        return $stmt->fetchAll();
    }

    /** All accounts, including inactive — for admin Chart of Accounts browsing. */
    public function allAccounts(): array
    {
        return $this->db->query("SELECT * FROM `accounts` ORDER BY code")->fetchAll();
    }

    /** Whether this account has ever been referenced by a posted journal line. */
    public function hasJournalActivity(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `journal_lines` WHERE account_id = ?");
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Accounts are not part of the immutable ledger, but a Chart-of-Accounts
     * entry that already has journal activity must never be deleted (no
     * delete UI is exposed anywhere in this app — this is a defensive guard
     * only, matching the mutation-protection pattern used throughout).
     */
    public function delete(int $id): bool
    {
        if ($this->hasJournalActivity($id)) {
            throw new RuntimeException('This account has journal activity and cannot be deleted — deactivate it instead.');
        }
        return parent::delete($id);
    }
}
