<?php
/**
 * DatabaseRecoveryModel — SA-6 (2026-09). Thin CRUD wrapper over
 * `database_recoveries`. All restore/verification logic lives in
 * DatabaseRecoveryService.
 */
class DatabaseRecoveryModel extends Model
{
    protected string $table      = 'database_recoveries';
    protected string $primaryKey = 'id';

    public function record(array $backup, string $target, string $status, ?int $exitCode, ?string $output, ?string $error, int $userId): int
    {
        return (int)$this->create([
            'backup_id' => $backup['id'],
            'backup_filename' => $backup['filename'],
            'backup_checksum' => $backup['sha256'],
            'target_database' => $target,
            'status' => $status,
            'restore_exit_code' => $exitCode,
            'restore_output' => $output !== null ? mb_substr($output, 0, 5000) : null,
            'error_message' => $error,
            'actor_id' => $userId,
        ]);
    }

    public function updateVerification(int $id, string $status, array $summary): void
    {
        $this->update($id, [
            'status' => $status,
            'verification_summary_json' => json_encode($summary),
        ]);
    }

    public function recent(int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, u.full_name AS actor_name
            FROM `database_recoveries` r LEFT JOIN users u ON u.id = r.actor_id
            ORDER BY r.id DESC LIMIT " . (int)$limit
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
