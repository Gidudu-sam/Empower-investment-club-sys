<?php
/**
 * JournalEntryModel — posted journal entries.
 *
 * Immutable by design: update()/delete() are overridden to throw rather than
 * silently inherited from Model, since a posted entry may only ever be
 * corrected by posting a new reversing entry (reversal_of_id), never edited
 * or removed in place.
 */
class JournalEntryModel extends Model
{
    protected string $table      = 'journal_entries';
    protected string $primaryKey = 'id';

    public function update(int $id, array $data): bool
    {
        throw new RuntimeException('Journal entries are immutable — post a reversing entry instead of updating.');
    }

    public function delete(int $id): bool
    {
        throw new RuntimeException('Journal entries are immutable — post a reversing entry instead of deleting.');
    }

    public function findByEntryNumber(string $entryNumber): array|false
    {
        return $this->findWhere(['entry_number' => $entryNumber]);
    }

    public function findBySourceReference(string $sourceModule, string $sourceReferenceType, int $sourceReferenceId): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM `journal_entries`
             WHERE source_module = ? AND source_reference_type = ? AND source_reference_id = ?
             LIMIT 1"
        );
        $stmt->execute([$sourceModule, $sourceReferenceType, $sourceReferenceId]);
        return $stmt->fetch();
    }

    public function recent(int $limit = 50): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `journal_entries` ORDER BY entry_date DESC, id DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
