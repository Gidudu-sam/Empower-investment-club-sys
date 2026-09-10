<?php
/**
 * OpeningBalanceBatchModel — the Opening Balance maker-checker workflow.
 *
 * Workflow: draft -> pending_approval -> approved -> posted
 *                                     \-> rejected -> (corrected) -> draft -> ...
 *
 * update()/delete() are blocked entirely (matching JournalEntryModel's
 * immutability pattern) — every state change goes through a dedicated,
 * narrow method below that enforces the workflow rules. Posting delegates
 * to JournalService::post() — this model never writes to journal_entries
 * or journal_lines directly.
 */
class OpeningBalanceBatchModel extends Model
{
    protected string $table      = 'opening_balance_batches';
    protected string $primaryKey = 'id';

    private OpeningBalanceLineModel $lineModel;
    private AccountModel $accountModel;

    public function __construct()
    {
        parent::__construct();
        $this->lineModel    = new OpeningBalanceLineModel();
        $this->accountModel = new AccountModel();
    }

    public function update(int $id, array $data): bool
    {
        throw new RuntimeException('Opening balance batches do not support direct update() — use the workflow methods (submit/approve/reject/post/replaceLines).');
    }

    public function delete(int $id): bool
    {
        throw new RuntimeException('Opening balance batches are never deleted — reject and correct instead.');
    }

    public function getAll(): array
    {
        $stmt = $this->db->query("
            SELECT b.*, fy.name AS financial_year_name, ap.name AS accounting_period_name,
                   ue.full_name AS entered_by_name, ua.full_name AS approved_by_name,
                   ur.full_name AS rejected_by_name,
                   (SELECT COALESCE(SUM(debit),0) FROM opening_balances WHERE batch_id = b.id) AS total_debit,
                   (SELECT COALESCE(SUM(credit),0) FROM opening_balances WHERE batch_id = b.id) AS total_credit
            FROM `opening_balance_batches` b
            LEFT JOIN `financial_years` fy ON fy.id = b.financial_year_id
            LEFT JOIN `accounting_periods` ap ON ap.id = b.accounting_period_id
            LEFT JOIN `users` ue ON ue.id = b.entered_by
            LEFT JOIN `users` ua ON ua.id = b.approved_by
            LEFT JOIN `users` ur ON ur.id = b.rejected_by
            ORDER BY b.created_at DESC, b.id DESC
        ");
        return $stmt->fetchAll();
    }

    public function findWithLines(int $id): array|false
    {
        $batch = $this->find($id);
        if (!$batch) {
            return false;
        }
        $batch['lines'] = $this->lineModel->forBatch($id);
        return $batch;
    }

    public function auditTrail(int $batchId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, u.full_name AS user_name
            FROM `journal_entry_audit` a
            LEFT JOIN `users` u ON u.id = a.user_id
            WHERE a.entity_type = 'opening_balance_batch' AND a.entity_id = ?
            ORDER BY a.created_at ASC, a.id ASC
        ");
        $stmt->execute([$batchId]);
        return $stmt->fetchAll();
    }

    public function pendingApproval(): array
    {
        $stmt = $this->db->query("
            SELECT b.*, fy.name AS financial_year_name, ap.name AS accounting_period_name,
                   ue.full_name AS entered_by_name,
                   (SELECT COALESCE(SUM(debit),0) FROM opening_balances WHERE batch_id = b.id) AS total_debit,
                   (SELECT COALESCE(SUM(credit),0) FROM opening_balances WHERE batch_id = b.id) AS total_credit
            FROM `opening_balance_batches` b
            LEFT JOIN `financial_years` fy ON fy.id = b.financial_year_id
            LEFT JOIN `accounting_periods` ap ON ap.id = b.accounting_period_id
            LEFT JOIN `users` ue ON ue.id = b.entered_by
            WHERE b.status = 'pending_approval'
            ORDER BY b.submitted_at ASC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Create a new draft batch with its lines, fully validated and balanced.
     *
     * @throws InvalidArgumentException on any validation failure
     */
    public function createDraft(array $data, array $lines, int $userId): int
    {
        $this->validateLines($lines, (int)$data['financial_year_id']);

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $batchNumber = $this->nextBatchNumber();

            $batchId = $this->create([
                'batch_number'          => $batchNumber,
                'financial_year_id'     => $data['financial_year_id'],
                'accounting_period_id'  => $data['accounting_period_id'],
                'as_of_date'            => $data['as_of_date'],
                'status'                => 'draft',
                'entered_by'            => $userId,
            ]);
            if ($batchId === false) {
                throw new RuntimeException('Failed to create opening balance batch.');
            }

            $this->lineModel->replaceForBatch($batchId, $lines);
            $this->writeAudit($userId, 'created', $batchId, ['batch_number' => $batchNumber, 'lines' => $lines]);

            if ($ownTransaction) {
                $this->db->commit();
            }
            return $batchId;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Replace the lines of a draft or rejected batch (the "correct and
     * resubmit" path). Rejected batches revert to draft so they must be
     * explicitly re-submitted.
     */
    public function replaceLines(int $batchId, array $lines, int $userId): void
    {
        $batch = $this->find($batchId);
        if (!$batch) {
            throw new InvalidArgumentException("Opening balance batch id {$batchId} does not exist.");
        }
        if (!in_array($batch['status'], ['draft', 'rejected'], true)) {
            throw new InvalidArgumentException("Only draft or rejected batches can be edited (current status: {$batch['status']}).");
        }

        $this->validateLines($lines, (int)$batch['financial_year_id']);

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->lineModel->replaceForBatch($batchId, $lines);

            if ($batch['status'] === 'rejected') {
                $this->db->prepare(
                    "UPDATE `opening_balance_batches`
                     SET status = 'draft', rejected_by = NULL, rejected_at = NULL, rejection_reason = NULL
                     WHERE id = ?"
                )->execute([$batchId]);
            }

            $this->writeAudit($userId, 'corrected', $batchId, ['lines' => $lines]);
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function submit(int $batchId, int $userId): void
    {
        $batch = $this->find($batchId);
        if (!$batch) {
            throw new InvalidArgumentException("Opening balance batch id {$batchId} does not exist.");
        }
        if ($batch['status'] !== 'draft') {
            throw new InvalidArgumentException("Only a draft batch can be submitted (current status: {$batch['status']}).");
        }

        $lines = $this->lineModel->forBatch($batchId);
        $this->validateLines($lines, (int)$batch['financial_year_id'], $batchId);

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare(
                "UPDATE `opening_balance_batches` SET status = 'pending_approval', submitted_at = NOW() WHERE id = ?"
            )->execute([$batchId]);

            $this->writeAudit($userId, 'submitted', $batchId, ['status' => 'pending_approval']);
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function approve(int $batchId, int $userId): void
    {
        $batch = $this->find($batchId);
        if (!$batch) {
            throw new InvalidArgumentException("Opening balance batch id {$batchId} does not exist.");
        }
        if ($batch['status'] !== 'pending_approval') {
            throw new InvalidArgumentException("Only a pending-approval batch can be approved (current status: {$batch['status']}).");
        }
        if ((int)$batch['entered_by'] === $userId) {
            throw new InvalidArgumentException('The preparer of a batch may not approve their own batch.');
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare(
                "UPDATE `opening_balance_batches` SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?"
            )->execute([$userId, $batchId]);

            $this->writeAudit($userId, 'approved', $batchId, ['status' => 'approved']);
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function reject(int $batchId, int $userId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }

        $batch = $this->find($batchId);
        if (!$batch) {
            throw new InvalidArgumentException("Opening balance batch id {$batchId} does not exist.");
        }
        if ($batch['status'] !== 'pending_approval') {
            throw new InvalidArgumentException("Only a pending-approval batch can be rejected (current status: {$batch['status']}).");
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare(
                "UPDATE `opening_balance_batches`
                 SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ?
                 WHERE id = ?"
            )->execute([$userId, $reason, $batchId]);

            $this->writeAudit($userId, 'rejected', $batchId, ['status' => 'rejected'], $reason);
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Post an approved batch as a real, balanced journal entry via
     * JournalService::post() — never a direct journal_entries/journal_lines write.
     */
    public function post(int $batchId, int $userId): array
    {
        $batch = $this->find($batchId);
        if (!$batch) {
            throw new InvalidArgumentException("Opening balance batch id {$batchId} does not exist.");
        }
        if ($batch['status'] === 'posted') {
            // Already posted — return the existing journal reference (idempotent no-op).
            return ['journal_entry_id' => (int)$batch['journal_entry_id'], 'created' => false];
        }
        if ($batch['status'] !== 'approved') {
            throw new InvalidArgumentException("Only an approved batch can be posted (current status: {$batch['status']}).");
        }

        $lines = $this->lineModel->forBatch($batchId);
        $journalLines = array_map(fn($l) => [
            'account_id'  => (int)$l['account_id'],
            'debit'       => (float)$l['debit'],
            'credit'      => (float)$l['credit'],
            'description' => $l['description'] ?? ('Opening balance — ' . $l['account_code'] . ' ' . $l['account_name']),
        ], $lines);

        // One outer transaction covers the journal posting AND the batch's own
        // status update + audit row — JournalService::post() detects the
        // already-open transaction and joins it rather than committing on its
        // own, so all three either land together or roll back together. If a
        // caller already has a transaction open, join it instead of nesting.
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $service = new JournalService();
            $result = $service->post([
                'entry_date'             => $batch['as_of_date'],
                'description'            => "Opening Balance Batch {$batch['batch_number']}",
                'source_module'          => 'opening_balances',
                'source_reference_type'  => 'batch',
                'source_reference_id'    => $batchId,
                'financial_year_id'      => $batch['financial_year_id'],
                'accounting_period_id'   => $batch['accounting_period_id'],
                'created_by'             => $userId,
                'lines'                  => $journalLines,
            ]);

            $this->db->prepare(
                "UPDATE `opening_balance_batches` SET status = 'posted', posted_at = NOW(), journal_entry_id = ? WHERE id = ?"
            )->execute([$result['id'], $batchId]);

            $this->writeAudit($userId, 'posted', $batchId, [
                'journal_entry_id' => $result['id'],
                'entry_number'     => $result['entry_number'],
            ]);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return ['journal_entry_id' => $result['id'], 'entry_number' => $result['entry_number'], 'created' => $result['created']];
    }

    /**
     * Validate a line set: >=1 line, each line single-sided & non-negative,
     * every account exists & active, total debits == total credits (integer
     * cents comparison), and no account already has an APPROVED or POSTED
     * opening balance for the same financial year in a different batch.
     */
    private function validateLines(array $lines, int $financialYearId, ?int $excludeBatchId = null): void
    {
        if (count($lines) < 1) {
            throw new InvalidArgumentException('An opening balance batch needs at least one line.');
        }

        $debitCents  = 0;
        $creditCents = 0;
        $seenAccounts = [];

        foreach ($lines as $line) {
            $accountId = (int)($line['account_id'] ?? 0);
            $debit     = (float)($line['debit'] ?? 0);
            $credit    = (float)($line['credit'] ?? 0);

            if ($accountId <= 0) {
                throw new InvalidArgumentException('Every line requires an account_id.');
            }
            if (isset($seenAccounts[$accountId])) {
                throw new InvalidArgumentException("Account id {$accountId} appears more than once in this batch.");
            }
            $seenAccounts[$accountId] = true;

            $account = $this->accountModel->findActive($accountId);
            if (!$account) {
                throw new InvalidArgumentException("Account id {$accountId} does not exist or is not active.");
            }

            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException('Debit and credit amounts must not be negative.');
            }
            if (($debit > 0 && $credit > 0) || ($debit == 0 && $credit == 0)) {
                throw new InvalidArgumentException("Line for account id {$accountId} must be single-sided: exactly one of debit/credit greater than zero.");
            }

            $debitCents  += (int)round($debit * 100);
            $creditCents += (int)round($credit * 100);

            $conflictSql = "
                SELECT bb.id FROM opening_balances ob
                JOIN opening_balance_batches bb ON bb.id = ob.batch_id
                WHERE ob.account_id = ?
                  AND bb.financial_year_id = ?
                  AND bb.status IN ('approved','posted')";
            $params = [$accountId, $financialYearId];
            if ($excludeBatchId !== null) {
                $conflictSql .= " AND bb.id != ?";
                $params[] = $excludeBatchId;
            }
            $stmt = $this->db->prepare($conflictSql . " LIMIT 1");
            $stmt->execute($params);
            if ($stmt->fetch()) {
                throw new InvalidArgumentException("Account id {$accountId} already has an approved or posted opening balance for this financial year.");
            }
        }

        if ($debitCents !== $creditCents) {
            $d = number_format($debitCents / 100, 2);
            $c = number_format($creditCents / 100, 2);
            throw new InvalidArgumentException("Batch is not balanced: total debits {$d} != total credits {$c}.");
        }
    }

    private function nextBatchNumber(): string
    {
        $stmt = $this->db->prepare("SELECT last_number FROM `journal_number_sequences` WHERE prefix = 'OB' FOR UPDATE");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No journal_number_sequences row for prefix 'OB'.");
        }
        $next = (int)$row['last_number'] + 1;
        $this->db->prepare("UPDATE `journal_number_sequences` SET last_number = ? WHERE prefix = 'OB'")->execute([$next]);
        return sprintf('OB-%06d', $next);
    }

    private function writeAudit(int $userId, string $action, int $batchId, array $afterData, ?string $reason = null): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `journal_entry_audit` (user_id, action, entity_type, entity_id, before_json, after_json, reason, ip_address)
             VALUES (?, ?, 'opening_balance_batch', ?, NULL, ?, ?, ?)"
        );
        $stmt->execute([
            $userId,
            $action,
            $batchId,
            json_encode($afterData),
            $reason,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}
