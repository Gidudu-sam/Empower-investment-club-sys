<?php
/**
 * ControlledCorrectionModel — SA-5 (2026-09).
 *
 * Thin CRUD wrapper over the `corrections` table. All correction business
 * logic (validation, safety gates, transaction boundaries, calling
 * JournalService) lives in ControlledCorrectionService, not here.
 */
class ControlledCorrectionModel extends Model
{
    protected string $table      = 'corrections';
    protected string $primaryKey = 'id';

    public function findByNumber(string $number): array|false
    {
        return $this->findWhere(['correction_number' => $number]);
    }

    public function nextCorrectionNumber(): string
    {
        $stmt = $this->db->query("SELECT correction_number FROM `corrections` ORDER BY id DESC LIMIT 1");
        $last = $stmt->fetchColumn();
        $next = $last ? ((int)substr($last, 4)) + 1 : 1;
        return 'COR-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
    }

    public function recent(int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, u1.full_name AS prepared_by_name, u2.full_name AS executed_by_name
            FROM `corrections` c
            LEFT JOIN users u1 ON u1.id = c.prepared_by
            LEFT JOIN users u2 ON u2.id = c.executed_by
            ORDER BY c.id DESC LIMIT " . (int)$limit
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
