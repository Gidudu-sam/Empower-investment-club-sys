<?php
/**
 * JournalLineModel — lines belonging to a journal entry.
 *
 * Read-only from the outside: the generic single-row create()/update()/delete()
 * are overridden to throw, since a line only ever makes sense as part of a
 * balanced set written atomically by JournalService::post(). createBulk() is
 * the sole write path, and is only ever called from inside JournalService's
 * own transaction.
 */
class JournalLineModel extends Model
{
    protected string $table      = 'journal_lines';
    protected string $primaryKey = 'id';

    public function create(array $data): int|false
    {
        throw new RuntimeException('Journal lines are only ever written as a balanced set — use JournalService::post().');
    }

    public function update(int $id, array $data): bool
    {
        throw new RuntimeException('Journal lines are immutable — post a reversing entry instead of updating.');
    }

    public function delete(int $id): bool
    {
        throw new RuntimeException('Journal lines are immutable — post a reversing entry instead of deleting.');
    }

    /**
     * Insert every line for one journal entry. Must be called inside an
     * already-open transaction (JournalService::post() owns it).
     */
    public function createBulk(int $journalEntryId, array $lines): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `journal_lines` (journal_entry_id, account_id, debit, credit, description)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($lines as $line) {
            $stmt->execute([
                $journalEntryId,
                $line['account_id'],
                $line['debit'],
                $line['credit'],
                $line['description'] ?? null,
            ]);
        }
    }

    public function forEntry(int $journalEntryId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `journal_lines` WHERE journal_entry_id = ? ORDER BY id");
        $stmt->execute([$journalEntryId]);
        return $stmt->fetchAll();
    }

    public function forAccount(int $accountId): array
    {
        $stmt = $this->db->prepare(
            "SELECT jl.*, je.entry_number, je.entry_date
             FROM `journal_lines` jl
             JOIN `journal_entries` je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ?
             ORDER BY je.entry_date, jl.id"
        );
        $stmt->execute([$accountId]);
        return $stmt->fetchAll();
    }
}
