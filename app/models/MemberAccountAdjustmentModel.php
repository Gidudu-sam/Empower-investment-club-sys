<?php
/**
 * MemberAccountAdjustmentModel — Member Account Credit/Debit Adjustments.
 *
 * The "select a specific member's account and credit/debit it" capability
 * the voucher audit found missing. Deliberately distinct from
 * InternalVoucherModel (a plain GL-to-GL voucher, no member) -- an
 * adjustment always has BOTH sides: a member-subsidiary-ledger row (in
 * `savings`, transaction_type='adjustment' -- an ENUM value that already
 * existed but had no code path setting it until now) AND a GL journal
 * entry, written atomically together, exactly like a normal deposit/
 * withdrawal does via SavingsModel::recordDepositWithPosting(). This is
 * the fix for the audited gap: a voucher only ever touched the GL side.
 *
 * Workflow: draft -> pending_approval -> approved -> posted
 *                                      \-> rejected -> (corrected) -> draft -> ...
 * A posted adjustment is immutable. A correction is a reversal (own
 * method, admin-only, mandatory reason) that creates an equal-and-
 * opposite entry on BOTH sides -- it never edits or deletes the original.
 *
 * Accounting rule, mirroring SavingsModel's own deposit/withdrawal
 * postings exactly (not invented -- see SavingsModel::SAVINGS_LIABILITY_ACCOUNT
 * and postDeposit()/postWithdrawal()):
 *   Credit adjustment (increases what the club owes the member):
 *     Dr contra_account_id (preparer-selected) / Cr Members' Savings Liability (2020)
 *   Debit adjustment (decreases what the club owes the member):
 *     Dr Members' Savings Liability (2020) / Cr contra_account_id (preparer-selected)
 */
class MemberAccountAdjustmentModel extends Model
{
    protected string $table      = 'member_account_adjustments';
    protected string $primaryKey = 'id';

    /** Members' Savings liability account (2020) — identical constant to SavingsModel's, not guessed. */
    private const SAVINGS_LIABILITY_ACCOUNT = 17;

    /** Immutability guard — mirrors InternalVoucherModel::update()/delete() exactly. */
    public function update(int $id, array $data): bool
    {
        $current = $this->find($id);
        if ($current && !in_array($current['status'], ['draft', 'rejected'], true)) {
            throw new RuntimeException('Only a draft or rejected adjustment can be edited — use the workflow methods (submit/approve/reject/post/reverse) otherwise.');
        }
        return parent::update($id, $data);
    }

    public function delete(int $id): bool
    {
        $current = $this->find($id);
        if ($current && $current['status'] !== 'draft') {
            throw new RuntimeException('Only a draft adjustment can be deleted.');
        }
        return parent::delete($id);
    }

    /** Reasons this low-effort/meaningless must be rejected — Phase 7's explicit examples. */
    private const REJECTED_REASON_VALUES = ['test', 'correction', 'adjustment', 'fix', 'n/a', 'na', '-', '.', 'x'];

    public function getAll(): array
    {
        return $this->db->query("
            SELECT a.*,
                   m.first_name, m.last_name, m.member_number,
                   sa.account_number, sa.account_type,
                   ur.full_name AS recorded_by_name, ua.full_name AS approved_by_name,
                   je.entry_number
            FROM `member_account_adjustments` a
            JOIN `members` m ON m.id = a.member_id
            JOIN `member_savings_accounts` sa ON sa.id = a.savings_account_id
            LEFT JOIN `users` ur ON ur.id = a.recorded_by
            LEFT JOIN `users` ua ON ua.id = a.approved_by
            LEFT JOIN `journal_entries` je ON je.id = a.journal_entry_id
            ORDER BY a.created_at DESC, a.id DESC
        ")->fetchAll();
    }

    public function findWithDetails(int $id): array|false
    {
        $stmt = $this->db->prepare("
            SELECT a.*,
                   m.first_name, m.last_name, m.member_number,
                   sa.account_number, sa.account_type,
                   ca.code AS contra_account_code, ca.name AS contra_account_name,
                   ur.full_name AS recorded_by_name, ua.full_name AS approved_by_name,
                   uj.full_name AS rejected_by_name, uv.full_name AS reversed_by_name,
                   je.entry_number
            FROM `member_account_adjustments` a
            JOIN `members` m ON m.id = a.member_id
            JOIN `member_savings_accounts` sa ON sa.id = a.savings_account_id
            JOIN `accounts` ca ON ca.id = a.contra_account_id
            LEFT JOIN `users` ur ON ur.id = a.recorded_by
            LEFT JOIN `users` ua ON ua.id = a.approved_by
            LEFT JOIN `users` uj ON uj.id = a.rejected_by
            LEFT JOIN `users` uv ON uv.id = a.reversed_by
            LEFT JOIN `journal_entries` je ON je.id = a.journal_entry_id
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /** Adjustments awaiting approval — mirrors InternalVoucherModel/InvestmentModel's own pendingApproval(). */
    public function pendingApproval(): array
    {
        return $this->db->query("
            SELECT a.*, m.first_name, m.last_name, m.member_number, ur.full_name AS recorded_by_name
            FROM `member_account_adjustments` a
            JOIN `members` m ON m.id = a.member_id
            LEFT JOIN `users` ur ON ur.id = a.recorded_by
            WHERE a.status = 'pending_approval'
            ORDER BY a.submitted_at ASC
        ")->fetchAll();
    }

    /** Adjustments for one savings account — Phase 20's account-history widget. */
    public function forAccount(int $accountId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, ur.full_name AS recorded_by_name
            FROM `member_account_adjustments` a
            LEFT JOIN `users` ur ON ur.id = a.recorded_by
            WHERE a.savings_account_id = ?
            ORDER BY a.created_at DESC, a.id DESC
        ");
        $stmt->execute([$accountId]);
        return $stmt->fetchAll();
    }

    public function auditTrail(int $adjustmentId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, u.full_name AS user_name
            FROM `journal_entry_audit` a
            LEFT JOIN `users` u ON u.id = a.user_id
            WHERE a.entity_type = 'member_account_adjustment' AND a.entity_id = ?
            ORDER BY a.created_at ASC, a.id ASC
        ");
        $stmt->execute([$adjustmentId]);
        return $stmt->fetchAll();
    }

    /**
     * Member's own eligible accounts for this feature — Phase 5. Reuses
     * SavingsAccountHolderModel::getMemberAccounts() (already correctly
     * includes joint accounts the member is a holder of, via the holders
     * join, and naturally excludes corporate since only the organization
     * itself -- never a member -- is ever a corporate account's holder).
     * Corporate is defensively excluded here too, matching the existing
     * restriction already enforced in SavingsAccountController.
     */
    public function eligibleAccountsForMember(int $memberId): array
    {
        $accounts = (new SavingsAccountHolderModel())->getMemberAccounts($memberId);
        return array_values(array_filter($accounts, fn($a) => $a['account_type'] !== 'corporate' && $a['status'] === 'active'));
    }

    private function validateReason(string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required for every account adjustment.');
        }
        if (strlen($reason) < 10) {
            throw new InvalidArgumentException('The reason must meaningfully explain the adjustment (at least 10 characters) — "test", "correction", or similar placeholders are not accepted.');
        }
        if (in_array(strtolower($reason), self::REJECTED_REASON_VALUES, true)) {
            throw new InvalidArgumentException('That reason is too generic. Explain specifically what happened, e.g. "Correction of wrongly posted transaction — amount posted to this member in error on 28 Aug 2026."');
        }
    }

    /**
     * Create a draft adjustment. Validates the member/account relationship
     * server-side (never trusts client-side filtering) — Phase 5's explicit
     * requirement, and Phase 27's test case.
     */
    public function createDraft(array $data, int $userId): int
    {
        $memberId  = (int)($data['member_id'] ?? 0);
        $accountId = (int)($data['savings_account_id'] ?? 0);
        $type      = $data['adjustment_type'] ?? '';
        $amount    = (float)($data['amount'] ?? 0);
        $reason    = $data['reason'] ?? '';
        $contraId  = (int)($data['contra_account_id'] ?? 0);
        $originalRef = trim($data['original_reference'] ?? '') ?: null;

        if ($memberId < 1 || !(new MemberModel())->find($memberId)) {
            throw new InvalidArgumentException('Select a valid member.');
        }
        if (!in_array($type, ['credit', 'debit'], true)) {
            throw new InvalidArgumentException('Select a valid adjustment type (credit or debit).');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
        $this->validateReason($reason);

        // Server-side account/member relationship check — never trust the
        // client. The account must exist, belong to this member (via the
        // holders table), be an eligible type/status.
        $accountModel = new MemberSavingsAccountModel();
        $account = $accountModel->getAccount($accountId);
        if (!$account) {
            throw new InvalidArgumentException('Selected account does not exist.');
        }
        if ($account['account_type'] === 'corporate') {
            throw new InvalidArgumentException('Corporate accounts are not supported by this workflow (no holder-authorization rule exists for them, matching the existing restriction on deposits/withdrawals).');
        }
        if ($account['status'] !== 'active') {
            throw new InvalidArgumentException('This account is not active.');
        }
        $holderIds = array_map(fn($h) => (int)$h['member_id'], array_filter(
            (new SavingsAccountHolderModel())->getAccountHolders($accountId),
            fn($h) => !empty($h['member_id'])
        ));
        if (!in_array($memberId, $holderIds, true)) {
            throw new InvalidArgumentException('This account does not belong to the selected member.');
        }

        $contraAccount = (new AccountModel())->findActive($contraId);
        if (!$contraAccount) {
            throw new InvalidArgumentException('Select a valid contra (other side) GL account.');
        }
        if ($contraId === self::SAVINGS_LIABILITY_ACCOUNT) {
            throw new InvalidArgumentException('The contra account cannot be the Members\' Savings liability account itself.');
        }

        // For a debit adjustment, warn-and-block if it would take the
        // account negative -- matches the general expectation that a
        // savings liability should not go below zero; the preparer can
        // still choose a smaller amount.
        if ($type === 'debit') {
            $currentBalance = $accountModel->getAccountBalance($accountId);
            if ($amount > $currentBalance + 0.01) {
                throw new InvalidArgumentException(sprintf(
                    'Debit of Shs %s would take this account below zero (current balance: Shs %s). Reduce the amount or verify the account.',
                    number_format($amount, 2), number_format($currentBalance, 2)
                ));
            }
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $adjustmentNumber = $this->nextAdjustmentNumber();

            $id = $this->create([
                'adjustment_number'  => $adjustmentNumber,
                'member_id'          => $memberId,
                'savings_account_id' => $accountId,
                'adjustment_type'    => $type,
                'amount'             => $amount,
                'reason'             => trim($reason),
                'original_reference' => $originalRef,
                'contra_account_id'  => $contraId,
                'status'             => 'draft',
                'recorded_by'        => $userId,
            ]);
            if ($id === false) {
                throw new RuntimeException('Failed to create the adjustment.');
            }

            $this->writeAudit($userId, 'created', $id, ['adjustment_number' => $adjustmentNumber, 'adjustment_type' => $type, 'amount' => $amount]);

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

    public function submit(int $id, int $userId): void
    {
        $adj = $this->find($id);
        if (!$adj) {
            throw new InvalidArgumentException("Adjustment id {$id} does not exist.");
        }
        if (!in_array($adj['status'], ['draft', 'rejected'], true)) {
            throw new InvalidArgumentException("Only a draft or rejected adjustment can be submitted (current status: {$adj['status']}).");
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) { $this->db->beginTransaction(); }
        try {
            $this->db->prepare(
                "UPDATE `member_account_adjustments` SET status = 'pending_approval', submitted_at = NOW(),
                 rejected_by = NULL, rejected_at = NULL, rejection_reason = NULL WHERE id = ?"
            )->execute([$id]);
            $this->writeAudit($userId, 'submitted', $id, ['status' => 'pending_approval']);
            if ($ownTransaction) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    public function approve(int $id, int $userId): void
    {
        $adj = $this->find($id);
        if (!$adj) {
            throw new InvalidArgumentException("Adjustment id {$id} does not exist.");
        }
        if ($adj['status'] !== 'pending_approval') {
            throw new InvalidArgumentException("Only a pending-approval adjustment can be approved (current status: {$adj['status']}).");
        }
        if ((int)$adj['recorded_by'] === $userId) {
            throw new InvalidArgumentException('The preparer of an adjustment may not approve their own adjustment.');
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) { $this->db->beginTransaction(); }
        try {
            $this->db->prepare(
                "UPDATE `member_account_adjustments` SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?"
            )->execute([$userId, $id]);
            $this->writeAudit($userId, 'approved', $id, ['status' => 'approved']);
            if ($ownTransaction) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    public function reject(int $id, int $userId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }
        $adj = $this->find($id);
        if (!$adj) {
            throw new InvalidArgumentException("Adjustment id {$id} does not exist.");
        }
        if ($adj['status'] !== 'pending_approval') {
            throw new InvalidArgumentException("Only a pending-approval adjustment can be rejected (current status: {$adj['status']}).");
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) { $this->db->beginTransaction(); }
        try {
            $this->db->prepare(
                "UPDATE `member_account_adjustments` SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ? WHERE id = ?"
            )->execute([$userId, $reason, $id]);
            $this->writeAudit($userId, 'rejected', $id, ['status' => 'rejected'], $reason);
            if ($ownTransaction) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /**
     * Post an approved adjustment. Writes BOTH sides atomically: a
     * `savings` row (transaction_type='adjustment', debit/credit and
     * running_balance computed the same way Stage 5B's SavingsModel does
     * for an account-linked row -- SavingsModel::create() itself is left
     * untouched, this model computes the equivalent shape directly rather
     * than risk changing that shared, heavily-used override) AND a
     * balanced JournalService entry. If either side fails, both roll back
     * -- this is the fix for the audited GL/member-ledger mismatch.
     * Idempotent: returns the existing reference if already posted.
     */
    public function post(int $id, int $userId): array
    {
        $adj = $this->find($id);
        if (!$adj) {
            throw new InvalidArgumentException("Adjustment id {$id} does not exist.");
        }
        if (!empty($adj['journal_entry_id'])) {
            $je = $this->db->prepare("SELECT entry_number FROM journal_entries WHERE id = ?");
            $je->execute([$adj['journal_entry_id']]);
            return ['journal_entry_id' => (int)$adj['journal_entry_id'], 'entry_number' => $je->fetchColumn(), 'created' => false];
        }
        if ($adj['status'] !== 'approved') {
            throw new InvalidArgumentException("Only an approved adjustment can be posted (current status: {$adj['status']}).");
        }

        $accountModel = new MemberSavingsAccountModel();
        $account = $accountModel->getAccount((int)$adj['savings_account_id']);
        if (!$account || $account['status'] !== 'active') {
            throw new InvalidArgumentException('The account is no longer active.');
        }

        $liabilityAccount = (new AccountModel())->findActive(self::SAVINGS_LIABILITY_ACCOUNT);
        if (!$liabilityAccount) {
            throw new InvalidArgumentException('The Members\' Savings liability account does not exist or is inactive.');
        }
        $contraAccount = (new AccountModel())->findActive((int)$adj['contra_account_id']);
        if (!$contraAccount) {
            throw new InvalidArgumentException('The contra account no longer exists or is not active.');
        }

        $amount = (float)$adj['amount'];
        $isCredit = $adj['adjustment_type'] === 'credit';
        $balanceBefore = $accountModel->getAccountBalance((int)$adj['savings_account_id']);
        if (!$isCredit && $amount > $balanceBefore + 0.01) {
            throw new InvalidArgumentException(sprintf(
                'Debit of Shs %s would take this account below zero (current balance: Shs %s). Posting blocked.',
                number_format($amount, 2), number_format($balanceBefore, 2)
            ));
        }
        $balanceAfter = $isCredit ? $balanceBefore + $amount : $balanceBefore - $amount;

        $member = (new MemberModel())->find((int)$adj['member_id']);
        $memberLabel = $member ? trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) : ('member #' . $adj['member_id']);
        $entryDate = date('Y-m-d');

        $lines = $isCredit
            ? [
                ['account_id' => $contraAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => $adj['reason']],
                ['account_id' => $liabilityAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => $adj['reason']],
            ]
            : [
                ['account_id' => $liabilityAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => $adj['reason']],
                ['account_id' => $contraAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => $adj['reason']],
            ];

        $periodStmt = $this->db->prepare("
            SELECT ap.id AS period_id, ap.financial_year_id
            FROM `accounting_periods` ap
            LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
            WHERE ap.status = 'open' AND ap.start_date <= ? AND ap.end_date >= ?
              AND (fy.status = 'active' OR fy.status IS NULL)
            LIMIT 1
        ");
        $periodStmt->execute([$entryDate, $entryDate]);
        $period = $periodStmt->fetch();

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) { $this->db->beginTransaction(); }
        try {
            // --- Side 1: member subsidiary ledger (the fix) ---
            $savingsStmt = $this->db->prepare("
                INSERT INTO `savings`
                    (member_id, savings_account_id, receipt_number, transaction_type, debit, credit,
                     running_balance, description, payment_method, transaction_date, financial_year, notes, recorded_by)
                VALUES (?, ?, ?, 'adjustment', ?, ?, ?, ?, 'Other', ?, ?, ?, ?)
            ");
            $savingsStmt->execute([
                $adj['member_id'], $adj['savings_account_id'], $adj['adjustment_number'],
                $isCredit ? 0.00 : $amount, $isCredit ? $amount : 0.00,
                $balanceAfter,
                "Account Adjustment ({$adj['adjustment_type']}) — {$adj['reason']}",
                $entryDate, date('Y'), $adj['reason'], $userId,
            ]);
            $savingsId = (int)$this->db->lastInsertId();

            // --- Side 2: GL ---
            $service = new JournalService();
            $result = $service->post([
                'entry_date'             => $entryDate,
                'description'            => "Account Adjustment {$adj['adjustment_number']} — {$memberLabel} ({$account['account_number']}) — {$adj['reason']}",
                'source_module'          => 'member_account_adjustments',
                'source_reference_type'  => 'adjustment',
                'source_reference_id'    => $id,
                'financial_year_id'      => $period['financial_year_id'] ?? null,
                'accounting_period_id'   => $period['period_id'] ?? null,
                'created_by'             => $userId,
                'lines'                  => $lines,
            ]);

            $this->db->prepare(
                "UPDATE `member_account_adjustments`
                 SET status = 'posted', posted_at = NOW(), journal_entry_id = ?, savings_id = ?,
                     balance_before = ?, balance_after = ?
                 WHERE id = ?"
            )->execute([$result['id'], $savingsId, $balanceBefore, $balanceAfter, $id]);

            $this->writeAudit($userId, 'posted', $id, [
                'journal_entry_id' => $result['id'], 'entry_number' => $result['entry_number'],
                'savings_id' => $savingsId, 'balance_before' => $balanceBefore, 'balance_after' => $balanceAfter,
            ]);

            if ($ownTransaction) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }

        return [
            'journal_entry_id' => $result['id'], 'entry_number' => $result['entry_number'], 'created' => $result['created'],
            'savings_id' => $savingsId, 'balance_before' => $balanceBefore, 'balance_after' => $balanceAfter,
        ];
    }

    /**
     * Reverse a posted adjustment: mirror-image GL entry via the existing
     * JournalService::reverse() (reused, not duplicated, per the audit's
     * explicit instruction) PLUS an equal-and-opposite `savings` row so
     * the member ledger and GL move back together -- this is exactly the
     * discipline the original bug lacked. Admin-only, mandatory reason,
     * single atomic step (matching JournalService::reverse()'s own
     * existing precedent: single-step, reasoned, admin-gated at the
     * controller). The original row is never edited — status stays
     * 'posted', reversal fields are the only thing set.
     */
    public function reverse(int $id, int $userId, string $reason): array
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reversal reason is required.');
        }
        $adj = $this->find($id);
        if (!$adj) {
            throw new InvalidArgumentException("Adjustment id {$id} does not exist.");
        }
        if ($adj['status'] !== 'posted') {
            throw new InvalidArgumentException("Only a posted adjustment can be reversed (current status: {$adj['status']}).");
        }
        if (!empty($adj['reversed_at'])) {
            throw new InvalidArgumentException('This adjustment has already been reversed.');
        }

        $accountModel = new MemberSavingsAccountModel();
        $isCredit = $adj['adjustment_type'] === 'credit';
        $amount = (float)$adj['amount'];
        $balanceBefore = $accountModel->getAccountBalance((int)$adj['savings_account_id']);
        // The reversal is the opposite direction of the original.
        $balanceAfter = $isCredit ? $balanceBefore - $amount : $balanceBefore + $amount;

        $entryDate = date('Y-m-d');

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) { $this->db->beginTransaction(); }
        try {
            // --- Side 1: GL reversal, via the existing canonical engine ---
            $service = new JournalService();
            $reversalResult = $service->reverse((int)$adj['journal_entry_id'], $userId, "Reversal of {$adj['adjustment_number']}: {$reason}");

            // --- Side 2: offsetting member-ledger row ---
            $savingsStmt = $this->db->prepare("
                INSERT INTO `savings`
                    (member_id, savings_account_id, receipt_number, transaction_type, debit, credit,
                     running_balance, description, payment_method, transaction_date, financial_year, notes, recorded_by)
                VALUES (?, ?, ?, 'adjustment', ?, ?, ?, ?, 'Other', ?, ?, ?, ?)
            ");
            $savingsStmt->execute([
                $adj['member_id'], $adj['savings_account_id'], $adj['adjustment_number'] . '-REV',
                $isCredit ? $amount : 0.00, $isCredit ? 0.00 : $amount,
                $balanceAfter,
                "Reversal of {$adj['adjustment_number']} — {$reason}",
                $entryDate, date('Y'), $reason, $userId,
            ]);
            $reversalSavingsId = (int)$this->db->lastInsertId();

            $this->db->prepare(
                "UPDATE `member_account_adjustments`
                 SET reversed_by = ?, reversed_at = NOW(), reversal_reason = ?,
                     reversal_journal_entry_id = ?, reversal_savings_id = ?
                 WHERE id = ?"
            )->execute([$userId, $reason, $reversalResult['id'], $reversalSavingsId, $id]);

            $this->writeAudit($userId, 'reversed', $id, [
                'reversal_journal_entry_id' => $reversalResult['id'],
                'reversal_savings_id' => $reversalSavingsId, 'balance_before' => $balanceBefore, 'balance_after' => $balanceAfter,
            ], $reason);

            if ($ownTransaction) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }

        return ['reversal_savings_id' => $reversalSavingsId, 'balance_before' => $balanceBefore, 'balance_after' => $balanceAfter];
    }

    public function peekNextAdjustmentNumber(): string
    {
        $stmt = $this->db->query("SELECT last_number FROM `journal_number_sequences` WHERE prefix = 'ADJ'");
        $row = $stmt->fetch();
        $next = (int)($row['last_number'] ?? 0) + 1;
        return sprintf('ADJ-%06d', $next);
    }

    private function nextAdjustmentNumber(): string
    {
        $stmt = $this->db->prepare("SELECT last_number FROM `journal_number_sequences` WHERE prefix = 'ADJ' FOR UPDATE");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No journal_number_sequences row for prefix 'ADJ'.");
        }
        $next = (int)$row['last_number'] + 1;
        $this->db->prepare("UPDATE `journal_number_sequences` SET last_number = ? WHERE prefix = 'ADJ'")->execute([$next]);
        return sprintf('ADJ-%06d', $next);
    }

    private function writeAudit(int $userId, string $action, int $adjustmentId, array $afterData, ?string $reason = null): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `journal_entry_audit` (user_id, action, entity_type, entity_id, before_json, after_json, reason, ip_address)
             VALUES (?, ?, 'member_account_adjustment', ?, NULL, ?, ?, ?)"
        );
        $stmt->execute([$userId, $action, $adjustmentId, json_encode($afterData), $reason, $_SERVER['REMOTE_ADDR'] ?? null]);
    }
}
