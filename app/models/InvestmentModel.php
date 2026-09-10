<?php
/**
 * InvestmentModel — the Investments maker-checker workflow.
 *
 * Workflow: draft -> pending_approval -> approved -> posted -> (matured|withdrawn|disposed)
 *                                     \-> rejected -> (corrected) -> draft -> ...
 *
 * update()/delete() are blocked entirely once submitted (matching
 * OpeningBalanceBatchModel's immutability pattern) — every state change
 * goes through a dedicated, narrow method below that enforces the
 * workflow rules. Posting delegates to JournalService::post() only —
 * this model never writes to journal_entries or journal_lines directly.
 */
class InvestmentModel extends Model
{
    protected string $table      = 'investments';
    protected string $primaryKey = 'id';

    private AccountModel $accountModel;
    private InvestmentTypeModel $typeModel;

    public function __construct()
    {
        parent::__construct();
        $this->accountModel = new AccountModel();
        $this->typeModel    = new InvestmentTypeModel();
    }

    public function update(int $id, array $data): bool
    {
        $current = $this->find($id);
        if ($current && $current['status'] !== 'draft' && $current['status'] !== 'rejected') {
            throw new RuntimeException('Only a draft or rejected investment can be edited — use the workflow methods (submit/approve/reject/post) otherwise.');
        }
        return parent::update($id, $data);
    }

    public function delete(int $id): bool
    {
        $current = $this->find($id);
        if ($current && $current['status'] !== 'draft') {
            throw new RuntimeException('Only a draft investment can be deleted.');
        }
        return parent::delete($id);
    }

    public function getAll(): array
    {
        return $this->db->query("
            SELECT i.*, it.type_name,
                   ia.code AS investment_account_code, ia.name AS investment_account_name,
                   fa.code AS funding_account_code, fa.name AS funding_account_name,
                   ur.full_name AS recorded_by_name, ua.full_name AS approved_by_name,
                   je.entry_number,
                   (i.principal_amount - COALESCE((
                       SELECT SUM(amount) FROM investment_transactions
                       WHERE investment_id = i.id AND transaction_type IN ('withdrawal','disposal') AND status = 'posted'
                   ), 0)) AS carrying_amount,
                   COALESCE((
                       SELECT SUM(amount) FROM investment_transactions
                       WHERE investment_id = i.id AND transaction_type = 'income' AND status = 'posted'
                   ), 0) AS income_received
            FROM `investments` i
            JOIN `investment_types` it ON it.id = i.investment_type_id
            JOIN `accounts` ia ON ia.id = i.investment_account_id
            JOIN `accounts` fa ON fa.id = i.funding_account_id
            LEFT JOIN `users` ur ON ur.id = i.recorded_by
            LEFT JOIN `users` ua ON ua.id = i.approved_by
            LEFT JOIN `journal_entries` je ON je.id = i.journal_entry_id
            ORDER BY i.created_at DESC, i.id DESC
        ")->fetchAll();
    }

    public function findWithDetails(int $id): array|false
    {
        $stmt = $this->db->prepare("
            SELECT i.*, it.type_name, it.income_gl_account_id, it.loss_gl_account_id,
                   ia.code AS investment_account_code, ia.name AS investment_account_name,
                   fa.code AS funding_account_code, fa.name AS funding_account_name,
                   ur.full_name AS recorded_by_name, ua.full_name AS approved_by_name,
                   uj.full_name AS rejected_by_name,
                   je.entry_number
            FROM `investments` i
            JOIN `investment_types` it ON it.id = i.investment_type_id
            JOIN `accounts` ia ON ia.id = i.investment_account_id
            JOIN `accounts` fa ON fa.id = i.funding_account_id
            LEFT JOIN `users` ur ON ur.id = i.recorded_by
            LEFT JOIN `users` ua ON ua.id = i.approved_by
            LEFT JOIN `users` uj ON uj.id = i.rejected_by
            LEFT JOIN `journal_entries` je ON je.id = i.journal_entry_id
            WHERE i.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        $row['carrying_amount']  = $this->carryingAmount($id, (float)$row['principal_amount']);
        $row['income_received']  = $this->incomeReceived($id);
        return $row;
    }

    /** principal_amount minus posted withdrawal/disposal amounts. Never negative. */
    public function carryingAmount(int $investmentId, ?float $principalAmount = null): float
    {
        if ($principalAmount === null) {
            $inv = $this->find($investmentId);
            if (!$inv) {
                throw new InvalidArgumentException("Investment id {$investmentId} does not exist.");
            }
            $principalAmount = (float)$inv['principal_amount'];
        }
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM `investment_transactions`
            WHERE investment_id = ? AND transaction_type IN ('withdrawal','disposal') AND status = 'posted'
        ");
        $stmt->execute([$investmentId]);
        $reduced = (float)$stmt->fetchColumn();
        return max(0.0, $principalAmount - $reduced);
    }

    public function incomeReceived(int $investmentId): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM `investment_transactions`
            WHERE investment_id = ? AND transaction_type = 'income' AND status = 'posted'
        ");
        $stmt->execute([$investmentId]);
        return (float)$stmt->fetchColumn();
    }

    public function transactions(int $investmentId): array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, u.full_name AS recorded_by_name, je.entry_number, a.code AS funding_account_code, a.name AS funding_account_name
            FROM `investment_transactions` t
            LEFT JOIN `users` u ON u.id = t.recorded_by
            LEFT JOIN `journal_entries` je ON je.id = t.journal_entry_id
            LEFT JOIN `accounts` a ON a.id = t.funding_account_id
            WHERE t.investment_id = ?
            ORDER BY t.transaction_date ASC, t.id ASC
        ");
        $stmt->execute([$investmentId]);
        return $stmt->fetchAll();
    }

    public function auditTrail(int $investmentId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, u.full_name AS user_name
            FROM `journal_entry_audit` a
            LEFT JOIN `users` u ON u.id = a.user_id
            WHERE a.entity_type = 'investment' AND a.entity_id = ?
            ORDER BY a.created_at ASC, a.id ASC
        ");
        $stmt->execute([$investmentId]);
        return $stmt->fetchAll();
    }

    public function pendingApproval(): array
    {
        return $this->db->query("
            SELECT i.*, it.type_name, ur.full_name AS recorded_by_name
            FROM `investments` i
            JOIN `investment_types` it ON it.id = i.investment_type_id
            LEFT JOIN `users` ur ON ur.id = i.recorded_by
            WHERE i.status = 'pending_approval'
            ORDER BY i.submitted_at ASC
        ")->fetchAll();
    }

    /**
     * Create a new draft investment. Validates required fields, resolves
     * the default investment_account_id from the type if not explicitly
     * overridden, and validates every referenced account is active.
     */
    public function createDraft(array $data, int $userId): int
    {
        if (empty($data['investment_type_id'])) {
            throw new InvalidArgumentException('An investment type is required.');
        }
        if (empty($data['principal_amount']) || (float)$data['principal_amount'] <= 0) {
            throw new InvalidArgumentException('Principal amount must be greater than zero.');
        }
        if (empty($data['start_date'])) {
            throw new InvalidArgumentException('Start date is required.');
        }
        if (!empty($data['maturity_date']) && $data['maturity_date'] < $data['start_date']) {
            throw new InvalidArgumentException('Maturity date cannot be before the start date.');
        }
        if (empty($data['funding_account_id'])) {
            throw new InvalidArgumentException('A funding account is required.');
        }

        $type = $this->typeModel->find((int)$data['investment_type_id']);
        if (!$type || !$type['is_active']) {
            throw new InvalidArgumentException('Selected investment type does not exist or is inactive.');
        }

        $investmentAccountId = !empty($data['investment_account_id'])
            ? (int)$data['investment_account_id']
            : (int)$type['asset_gl_account_id'];

        $investmentAccount = $this->accountModel->findActive($investmentAccountId);
        if (!$investmentAccount) {
            throw new InvalidArgumentException('The investment (asset) account does not exist or is not active.');
        }
        $fundingAccount = $this->accountModel->findActive((int)$data['funding_account_id']);
        if (!$fundingAccount) {
            throw new InvalidArgumentException('The funding account does not exist or is not active.');
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $investmentNumber = $this->nextInvestmentNumber();

            $id = $this->create([
                'investment_number'     => $investmentNumber,
                'investment_type_id'    => $type['id'],
                'reference'             => $data['reference'] ?? null,
                'provider_name'         => $data['provider_name'] ?? null,
                'principal_amount'      => $data['principal_amount'],
                'start_date'            => $data['start_date'],
                'maturity_date'         => $data['maturity_date'] ?? null,
                'expected_rate'         => $data['expected_rate'] ?? null,
                'investment_account_id' => $investmentAccountId,
                'funding_account_id'    => $data['funding_account_id'],
                'status'                => 'draft',
                'notes'                 => $data['notes'] ?? null,
                'recorded_by'           => $userId,
            ]);
            if ($id === false) {
                throw new RuntimeException('Failed to create investment.');
            }

            $this->writeAudit($userId, 'created', $id, ['investment_number' => $investmentNumber]);

            if ($ownTransaction) {
                $this->db->commit();
            }
            return $id;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function submit(int $investmentId, int $userId): void
    {
        $investment = $this->find($investmentId);
        if (!$investment) {
            throw new InvalidArgumentException("Investment id {$investmentId} does not exist.");
        }
        if (!in_array($investment['status'], ['draft', 'rejected'], true)) {
            throw new InvalidArgumentException("Only a draft or rejected investment can be submitted (current status: {$investment['status']}).");
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare(
                "UPDATE `investments` SET status = 'pending_approval', submitted_at = NOW(),
                 rejected_by = NULL, rejected_at = NULL, rejection_reason = NULL WHERE id = ?"
            )->execute([$investmentId]);

            $this->writeAudit($userId, 'submitted', $investmentId, ['status' => 'pending_approval']);
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

    public function approve(int $investmentId, int $userId): void
    {
        $investment = $this->find($investmentId);
        if (!$investment) {
            throw new InvalidArgumentException("Investment id {$investmentId} does not exist.");
        }
        if ($investment['status'] !== 'pending_approval') {
            throw new InvalidArgumentException("Only a pending-approval investment can be approved (current status: {$investment['status']}).");
        }
        if ((int)$investment['recorded_by'] === $userId) {
            throw new InvalidArgumentException('The preparer of an investment may not approve their own investment.');
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare(
                "UPDATE `investments` SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?"
            )->execute([$userId, $investmentId]);

            $this->writeAudit($userId, 'approved', $investmentId, ['status' => 'approved']);
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

    public function reject(int $investmentId, int $userId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }

        $investment = $this->find($investmentId);
        if (!$investment) {
            throw new InvalidArgumentException("Investment id {$investmentId} does not exist.");
        }
        if ($investment['status'] !== 'pending_approval') {
            throw new InvalidArgumentException("Only a pending-approval investment can be rejected (current status: {$investment['status']}).");
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare(
                "UPDATE `investments` SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ? WHERE id = ?"
            )->execute([$userId, $reason, $investmentId]);

            $this->writeAudit($userId, 'rejected', $investmentId, ['status' => 'rejected'], $reason);
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
     * Post an approved investment as a real, balanced journal entry via
     * JournalService::post(): Dr investment_account_id / Cr funding_account_id
     * for the principal amount. Idempotent: returns the existing reference
     * if already posted.
     */
    public function post(int $investmentId, int $userId): array
    {
        $investment = $this->find($investmentId);
        if (!$investment) {
            throw new InvalidArgumentException("Investment id {$investmentId} does not exist.");
        }
        if (!empty($investment['journal_entry_id'])) {
            $je = $this->db->prepare("SELECT entry_number FROM journal_entries WHERE id = ?");
            $je->execute([$investment['journal_entry_id']]);
            return ['journal_entry_id' => (int)$investment['journal_entry_id'], 'entry_number' => $je->fetchColumn(), 'created' => false];
        }
        if ($investment['status'] !== 'approved') {
            throw new InvalidArgumentException("Only an approved investment can be posted (current status: {$investment['status']}).");
        }

        $type = $this->typeModel->find((int)$investment['investment_type_id']);

        $period = $this->resolvePeriod($investment['start_date']);
        $amount = (float)$investment['principal_amount'];

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $service = new JournalService();
            $result = $service->post([
                'entry_date'             => $investment['start_date'],
                'description'            => "Investment placement {$investment['investment_number']} — {$type['type_name']}",
                'source_module'          => 'investments',
                'source_reference_type'  => 'investment_placement',
                'source_reference_id'    => $investmentId,
                'financial_year_id'      => $period['financial_year_id'] ?? null,
                'accounting_period_id'   => $period['period_id'] ?? null,
                'created_by'             => $userId,
                'lines'                  => [
                    ['account_id' => $investment['investment_account_id'], 'debit' => $amount, 'credit' => 0, 'description' => $type['type_name']],
                    ['account_id' => $investment['funding_account_id'], 'debit' => 0, 'credit' => $amount, 'description' => 'Investment placement'],
                ],
            ]);

            $this->db->prepare(
                "UPDATE `investments` SET status = 'posted', posted_at = NOW(), journal_entry_id = ? WHERE id = ?"
            )->execute([$result['id'], $investmentId]);

            $this->writeAudit($userId, 'posted', $investmentId, [
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
     * Same open-period-covering-this-date lookup used by every other
     * posting model (ExpenseModel/SavingsModel/LoanModel/OpeningBalanceBatchModel's
     * caller) — resolves both accounting_period_id and financial_year_id
     * explicitly since JournalService::post() does not auto-derive the
     * latter from the former.
     */
    public function resolvePeriod(string $date): array
    {
        $stmt = $this->db->prepare("
            SELECT ap.id AS period_id, ap.financial_year_id
            FROM `accounting_periods` ap
            LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
            WHERE ap.status = 'open'
              AND ap.start_date <= ? AND ap.end_date >= ?
              AND (fy.status = 'active' OR fy.status IS NULL)
            LIMIT 1
        ");
        $stmt->execute([$date, $date]);
        return $stmt->fetch() ?: [];
    }

    private function nextInvestmentNumber(): string
    {
        $stmt = $this->db->prepare("SELECT last_number FROM `journal_number_sequences` WHERE prefix = 'INV' FOR UPDATE");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No journal_number_sequences row for prefix 'INV'.");
        }
        $next = (int)$row['last_number'] + 1;
        $this->db->prepare("UPDATE `journal_number_sequences` SET last_number = ? WHERE prefix = 'INV'")->execute([$next]);
        return sprintf('INV-%06d', $next);
    }

    private function writeAudit(int $userId, string $action, int $investmentId, array $afterData, ?string $reason = null): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `journal_entry_audit` (user_id, action, entity_type, entity_id, before_json, after_json, reason, ip_address)
             VALUES (?, ?, 'investment', ?, NULL, ?, ?, ?)"
        );
        $stmt->execute([
            $userId,
            $action,
            $investmentId,
            json_encode($afterData),
            $reason,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}
