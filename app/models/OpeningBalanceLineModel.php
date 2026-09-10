<?php
/**
 * OpeningBalanceLineModel — line items belonging to an opening balance batch.
 *
 * Pure storage layer: no workflow/balance validation here (that belongs to
 * OpeningBalanceBatchModel, mirroring how JournalLineModel is a pure writer
 * and JournalService owns validation). update()/delete() are blocked —
 * lines are only ever replaced as a whole set via replaceForBatch().
 */
class OpeningBalanceLineModel extends Model
{
    protected string $table      = 'opening_balances';
    protected string $primaryKey = 'id';

    public function create(array $data): int|false
    {
        throw new RuntimeException('Opening balance lines are only ever written as a batch set — use replaceForBatch().');
    }

    public function update(int $id, array $data): bool
    {
        throw new RuntimeException('Opening balance lines are immutable once written — replace the whole batch instead.');
    }

    public function delete(int $id): bool
    {
        throw new RuntimeException('Opening balance lines are immutable once written — replace the whole batch instead.');
    }

    public function forBatch(int $batchId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ob.*, a.code AS account_code, a.name AS account_name, a.normal_balance
             FROM `opening_balances` ob
             JOIN `accounts` a ON a.id = ob.account_id
             WHERE ob.batch_id = ?
             ORDER BY a.code"
        );
        $stmt->execute([$batchId]);
        return $stmt->fetchAll();
    }

    /**
     * Replace every line for a batch. Caller (OpeningBalanceBatchModel) owns
     * validation and the surrounding transaction.
     */
    public function replaceForBatch(int $batchId, array $lines): void
    {
        $this->db->prepare("DELETE FROM `opening_balances` WHERE batch_id = ?")->execute([$batchId]);

        $stmt = $this->db->prepare(
            "INSERT INTO `opening_balances` (batch_id, account_id, debit, credit, description)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($lines as $line) {
            $stmt->execute([
                $batchId,
                $line['account_id'],
                $line['debit'] ?? 0,
                $line['credit'] ?? 0,
                $line['description'] ?? null,
            ]);
        }
    }
}
