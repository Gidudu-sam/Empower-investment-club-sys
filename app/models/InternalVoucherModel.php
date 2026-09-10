<?php
/**
 * InternalVoucherModel — Internal Debit/Credit Voucher maker-checker workflow.
 *
 * Workflow: draft -> pending_approval -> approved -> posted
 *                                     \-> rejected -> (corrected) -> draft -> ...
 *
 * Mirrors InvestmentModel/OpeningBalanceBatchModel exactly. Posting
 * delegates to JournalService::post() only — this model never writes to
 * journal_entries or journal_lines directly.
 *
 * Accounting rule (physical-voucher terminology preserved):
 *   Debit Voucher:  Dr primary_account_id / Cr contra_account_id
 *   Credit Voucher: Dr contra_account_id  / Cr primary_account_id
 *
 * Member subledger (savings only, this phase): when EITHER
 * primary_account_id or contra_account_id has accounts.requires_subledger=1
 * (never both — rejected as ambiguous), the voucher also carries member_id/
 * savings_account_id, and post() additionally writes a `savings` row
 * (transaction_type='adjustment') atomically alongside the GL entry —
 * the exact dual-write pattern proven in MemberAccountAdjustmentModel.
 * The GL account itself is never replaced by the member; the member
 * reference is subledger metadata on top of the same GL posting. Every
 * account without requires_subledger=1 (i.e. everything except id=17
 * this phase) behaves exactly as before this feature was added.
 *
 * Which side the flagged account sits on doesn't change the GL lines
 * (still built from voucher_type + primary/contra exactly as always) —
 * it only changes which direction the member's own balance moves:
 * resolveSubledgerSide() derives whether the flagged account plays the
 * debit or credit ROLE for this specific voucher, and that role (not
 * the raw voucher_type) drives the savings-row debit/credit split.
 */
class InternalVoucherModel extends Model
{
    protected string $table      = 'internal_vouchers';
    protected string $primaryKey = 'id';

    /** Subledger types this model knows how to dual-write for. Savings only, this phase. */
    private const SUPPORTED_SUBLEDGER_TYPES = ['savings'];

    private AccountModel $accountModel;

    public function __construct()
    {
        parent::__construct();
        $this->accountModel = new AccountModel();
    }

    /** Whether $account (a full accounts row) requires a member+subledger selection. */
    private function accountRequiresSubledger(array $account): bool
    {
        return (int)($account['requires_subledger'] ?? 0) === 1;
    }

    /**
     * Resolve which of the two accounts on this voucher (if either) requires
     * a member subledger, and whether that account plays the debit or
     * credit ROLE for this specific voucher — i.e. whichever of Dr
     * primary/Cr contra (debit voucher) or Dr contra/Cr primary (credit
     * voucher) actually lands on the flagged account. Returns null when
     * neither account requires one (the normal, unaffected case for every
     * account except id=17 this phase). Throws if both do — not a real
     * chart-of-accounts scenario today, but rejected explicitly rather than
     * silently picking one.
     */
    private function resolveSubledgerSide(array $primaryAccount, array $contraAccount, string $voucherType): ?array
    {
        $primaryRequires = $this->accountRequiresSubledger($primaryAccount);
        $contraRequires  = $this->accountRequiresSubledger($contraAccount);

        if ($primaryRequires && $contraRequires) {
            throw new InvalidArgumentException('Both the debit and credit accounts require a member subledger — this combination is not supported.');
        }
        if (!$primaryRequires && !$contraRequires) {
            return null;
        }

        $isDebitVoucher = $voucherType === 'debit';
        if ($primaryRequires) {
            // Debit Voucher: Dr primary. Credit Voucher: Cr primary.
            return ['account' => $primaryAccount, 'role' => $isDebitVoucher ? 'debit' : 'credit'];
        }
        // contraRequires: Debit Voucher: Cr contra. Credit Voucher: Dr contra.
        return ['account' => $contraAccount, 'role' => $isDebitVoucher ? 'credit' : 'debit'];
    }

    public function update(int $id, array $data): bool
    {
        $current = $this->find($id);
        if ($current && !in_array($current['status'], ['draft', 'rejected'], true)) {
            throw new RuntimeException('Only a draft or rejected voucher can be edited — use the workflow methods (submit/approve/reject/post) otherwise.');
        }
        return parent::update($id, $data);
    }

    public function delete(int $id): bool
    {
        $current = $this->find($id);
        if ($current && $current['status'] !== 'draft') {
            throw new RuntimeException('Only a draft voucher can be deleted.');
        }
        return parent::delete($id);
    }

    public function getAll(): array
    {
        return $this->db->query("
            SELECT v.*,
                   pa.code AS primary_account_code, pa.name AS primary_account_name,
                   ca.code AS contra_account_code, ca.name AS contra_account_name,
                   ec.category_name,
                   ur.full_name AS recorded_by_name, ua.full_name AS approved_by_name,
                   je.entry_number
            FROM `internal_vouchers` v
            JOIN `accounts` pa ON pa.id = v.primary_account_id
            JOIN `accounts` ca ON ca.id = v.contra_account_id
            LEFT JOIN `expense_categories` ec ON ec.id = v.expense_category_id
            LEFT JOIN `users` ur ON ur.id = v.recorded_by
            LEFT JOIN `users` ua ON ua.id = v.approved_by
            LEFT JOIN `journal_entries` je ON je.id = v.journal_entry_id
            ORDER BY v.created_at DESC, v.id DESC
        ")->fetchAll();
    }

    public function findWithDetails(int $id): array|false
    {
        $stmt = $this->db->prepare("
            SELECT v.*,
                   pa.code AS primary_account_code, pa.name AS primary_account_name,
                   ca.code AS contra_account_code, ca.name AS contra_account_name,
                   ec.category_name,
                   m.member_number, m.first_name AS member_first_name, m.last_name AS member_last_name,
                   sa.account_number AS savings_account_number, sa.account_type AS savings_account_type,
                   ur.full_name AS recorded_by_name, ua.full_name AS approved_by_name,
                   uj.full_name AS rejected_by_name,
                   je.entry_number
            FROM `internal_vouchers` v
            JOIN `accounts` pa ON pa.id = v.primary_account_id
            JOIN `accounts` ca ON ca.id = v.contra_account_id
            LEFT JOIN `expense_categories` ec ON ec.id = v.expense_category_id
            LEFT JOIN `members` m ON m.id = v.member_id
            LEFT JOIN `member_savings_accounts` sa ON sa.id = v.savings_account_id
            LEFT JOIN `users` ur ON ur.id = v.recorded_by
            LEFT JOIN `users` ua ON ua.id = v.approved_by
            LEFT JOIN `users` uj ON uj.id = v.rejected_by
            LEFT JOIN `journal_entries` je ON je.id = v.journal_entry_id
            WHERE v.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function auditTrail(int $voucherId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, u.full_name AS user_name
            FROM `journal_entry_audit` a
            LEFT JOIN `users` u ON u.id = a.user_id
            WHERE a.entity_type = 'internal_voucher' AND a.entity_id = ?
            ORDER BY a.created_at ASC, a.id ASC
        ");
        $stmt->execute([$voucherId]);
        return $stmt->fetchAll();
    }

    public function pendingApproval(): array
    {
        return $this->db->query("
            SELECT v.*, ur.full_name AS recorded_by_name
            FROM `internal_vouchers` v
            LEFT JOIN `users` ur ON ur.id = v.recorded_by
            WHERE v.status = 'pending_approval'
            ORDER BY v.submitted_at ASC
        ")->fetchAll();
    }

    /**
     * Create a draft voucher. For a debit voucher, an expense_category_id
     * may be supplied instead of an explicit primary_account_id — the
     * category's mapped GL account is resolved and stored as
     * primary_account_id (never left as a live lookup, so the voucher's
     * accounting stays fixed even if the category's mapping later
     * changes). If the category has no GL account mapped, this throws a
     * clear, non-technical message rather than silently assigning one.
     */
    public function createDraft(array $data, int $userId): int
    {
        if (empty($data['voucher_type']) || !in_array($data['voucher_type'], ['debit', 'credit'], true)) {
            throw new InvalidArgumentException('A valid voucher type (debit or credit) is required.');
        }
        if (empty($data['voucher_date'])) {
            throw new InvalidArgumentException('Voucher date is required.');
        }
        if (empty($data['amount']) || (float)$data['amount'] <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
        if (empty($data['narration']) || trim($data['narration']) === '') {
            throw new InvalidArgumentException('"Being" (narration) is required.');
        }
        if (empty($data['contra_account_id'])) {
            throw new InvalidArgumentException('A contra account is required.');
        }

        $expenseCategoryId = !empty($data['expense_category_id']) ? (int)$data['expense_category_id'] : null;
        $primaryAccountId = !empty($data['primary_account_id']) ? (int)$data['primary_account_id'] : null;

        if ($expenseCategoryId !== null) {
            $category = (new ExpenseCategoryModel())->find($expenseCategoryId);
            if (!$category || !$category['is_active']) {
                throw new InvalidArgumentException('Selected expense category does not exist or is inactive.');
            }
            if (empty($category['gl_account_id'])) {
                throw new InvalidArgumentException("This expense category has not been configured for accounting. Please ask an administrator to assign a GL account.");
            }
            $primaryAccountId = (int)$category['gl_account_id'];
        }

        if ($primaryAccountId === null) {
            throw new InvalidArgumentException('A ' . ($data['voucher_type'] === 'debit' ? 'debit' : 'credit') . ' account (or expense category) is required.');
        }

        $primaryAccount = $this->accountModel->findActive($primaryAccountId);
        if (!$primaryAccount) {
            throw new InvalidArgumentException('The selected account does not exist or is not active.');
        }
        $contraAccount = $this->accountModel->findActive((int)$data['contra_account_id']);
        if (!$contraAccount) {
            throw new InvalidArgumentException('The selected contra account does not exist or is not active.');
        }
        if ($primaryAccountId === (int)$data['contra_account_id']) {
            throw new InvalidArgumentException('The primary account and the contra account cannot be the same account.');
        }

        // Member subledger validation — savings only, this phase. Gated
        // entirely on whichever of primary/contra is flagged
        // requires_subledger (never both): for every other account (Cash,
        // Bank, expenses, Loans, Shares, etc.) none of this runs and
        // member_id/savings_account_id stay null regardless of what the
        // client sends.
        $memberId = null;
        $savingsAccountId = null;

        $subledger = $this->resolveSubledgerSide($primaryAccount, $contraAccount, $data['voucher_type']);
        if ($subledger !== null) {
            $subledgerAccount = $subledger['account'];
            $subledgerType = $subledgerAccount['subledger_type'];
            if (!in_array($subledgerType, self::SUPPORTED_SUBLEDGER_TYPES, true)) {
                throw new RuntimeException("Account \"{$subledgerAccount['name']}\" is flagged requires_subledger but has an unsupported subledger_type \"{$subledgerType}\".");
            }

            $memberId = (int)($data['member_id'] ?? 0);
            if ($memberId < 1 || !(new MemberModel())->find($memberId)) {
                throw new InvalidArgumentException('Select a valid member for this account.');
            }

            $savingsAccountId = (int)($data['savings_account_id'] ?? 0);
            $memberAccountModel = new MemberSavingsAccountModel();
            $memberAccount = $memberAccountModel->getAccount($savingsAccountId);
            if (!$memberAccount) {
                throw new InvalidArgumentException('Select a valid member savings account.');
            }
            if ($memberAccount['account_type'] === 'corporate') {
                throw new InvalidArgumentException('Corporate savings accounts are not supported by this workflow.');
            }
            if ($memberAccount['status'] !== 'active') {
                throw new InvalidArgumentException('This member savings account is not active.');
            }
            $holderIds = array_map(
                fn($h) => (int)$h['member_id'],
                array_filter((new SavingsAccountHolderModel())->getAccountHolders($savingsAccountId), fn($h) => !empty($h['member_id']))
            );
            if (!in_array($memberId, $holderIds, true)) {
                throw new InvalidArgumentException('This savings account does not belong to the selected member.');
            }

            // A debit ROLE (not necessarily a "debit voucher" — depends on
            // which side the flagged account is on) decreases the member's balance.
            if ($subledger['role'] === 'debit') {
                $currentBalance = $memberAccountModel->getAccountBalance($savingsAccountId);
                if ((float)$data['amount'] > $currentBalance + 0.01) {
                    throw new InvalidArgumentException(sprintf(
                        'Debit of Shs %s would take this member\'s savings account below zero (current balance: Shs %s). Reduce the amount or verify the account.',
                        number_format((float)$data['amount'], 2), number_format($currentBalance, 2)
                    ));
                }
            }
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $voucherNumber = $this->nextVoucherNumber();

            $id = $this->create([
                'voucher_number'      => $voucherNumber,
                'voucher_type'        => $data['voucher_type'],
                'voucher_date'        => $data['voucher_date'],
                'primary_account_id'  => $primaryAccountId,
                'contra_account_id'   => $data['contra_account_id'],
                'member_id'           => $memberId,
                'savings_account_id'  => $savingsAccountId,
                'expense_category_id' => $expenseCategoryId,
                'narration'           => trim($data['narration']),
                'amount'              => $data['amount'],
                'status'              => 'draft',
                'recorded_by'         => $userId,
            ]);
            if ($id === false) {
                throw new RuntimeException('Failed to create internal voucher.');
            }

            $this->writeAudit($userId, 'created', $id, ['voucher_number' => $voucherNumber, 'voucher_type' => $data['voucher_type']]);

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

    public function submit(int $voucherId, int $userId): void
    {
        $voucher = $this->find($voucherId);
        if (!$voucher) {
            throw new InvalidArgumentException("Internal voucher id {$voucherId} does not exist.");
        }
        if (!in_array($voucher['status'], ['draft', 'rejected'], true)) {
            throw new InvalidArgumentException("Only a draft or rejected voucher can be submitted (current status: {$voucher['status']}).");
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare(
                "UPDATE `internal_vouchers` SET status = 'pending_approval', submitted_at = NOW(),
                 rejected_by = NULL, rejected_at = NULL, rejection_reason = NULL WHERE id = ?"
            )->execute([$voucherId]);

            $this->writeAudit($userId, 'submitted', $voucherId, ['status' => 'pending_approval']);
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

    public function approve(int $voucherId, int $userId): void
    {
        $voucher = $this->find($voucherId);
        if (!$voucher) {
            throw new InvalidArgumentException("Internal voucher id {$voucherId} does not exist.");
        }
        if ($voucher['status'] !== 'pending_approval') {
            throw new InvalidArgumentException("Only a pending-approval voucher can be approved (current status: {$voucher['status']}).");
        }
        if ((int)$voucher['recorded_by'] === $userId) {
            throw new InvalidArgumentException('The preparer of a voucher may not approve their own voucher.');
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare(
                "UPDATE `internal_vouchers` SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?"
            )->execute([$userId, $voucherId]);

            $this->writeAudit($userId, 'approved', $voucherId, ['status' => 'approved']);
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

    public function reject(int $voucherId, int $userId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }

        $voucher = $this->find($voucherId);
        if (!$voucher) {
            throw new InvalidArgumentException("Internal voucher id {$voucherId} does not exist.");
        }
        if ($voucher['status'] !== 'pending_approval') {
            throw new InvalidArgumentException("Only a pending-approval voucher can be rejected (current status: {$voucher['status']}).");
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare(
                "UPDATE `internal_vouchers` SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ? WHERE id = ?"
            )->execute([$userId, $reason, $voucherId]);

            $this->writeAudit($userId, 'rejected', $voucherId, ['status' => 'rejected'], $reason);
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
     * Post an approved voucher as a balanced journal entry:
     *   Debit Voucher:  Dr primary_account_id / Cr contra_account_id
     *   Credit Voucher: Dr contra_account_id  / Cr primary_account_id
     * Idempotent: returns the existing reference if already posted.
     */
    public function post(int $voucherId, int $userId): array
    {
        $voucher = $this->find($voucherId);
        if (!$voucher) {
            throw new InvalidArgumentException("Internal voucher id {$voucherId} does not exist.");
        }
        if (!empty($voucher['journal_entry_id'])) {
            $je = $this->db->prepare("SELECT entry_number FROM journal_entries WHERE id = ?");
            $je->execute([$voucher['journal_entry_id']]);
            return [
                'journal_entry_id' => (int)$voucher['journal_entry_id'], 'entry_number' => $je->fetchColumn(), 'created' => false,
                'savings_id' => $voucher['savings_id'] !== null ? (int)$voucher['savings_id'] : null,
                'balance_before' => $voucher['balance_before'], 'balance_after' => $voucher['balance_after'],
            ];
        }
        if ($voucher['status'] !== 'approved') {
            throw new InvalidArgumentException("Only an approved voucher can be posted (current status: {$voucher['status']}).");
        }

        $primaryAccount = $this->accountModel->findActive((int)$voucher['primary_account_id']);
        if (!$primaryAccount) {
            throw new InvalidArgumentException('The primary account no longer exists or is not active.');
        }
        $contraAccount = $this->accountModel->findActive((int)$voucher['contra_account_id']);
        if (!$contraAccount) {
            throw new InvalidArgumentException('The contra account no longer exists or is not active.');
        }

        $amount = (float)$voucher['amount'];
        $isDebit = $voucher['voucher_type'] === 'debit';

        // Member subledger re-validation — savings only, this phase. Time
        // has passed since createDraft(), so status/balance are re-checked
        // fresh; ownership is not re-checked (cannot drift post-creation).
        // The GL $lines below are always built from voucher_type/primary/
        // contra as before — only the savings-row direction depends on
        // which side (if either) is flagged, via the resolved role.
        $subledger = $this->resolveSubledgerSide($primaryAccount, $contraAccount, $voucher['voucher_type']);
        $requiresSubledger = $subledger !== null;
        $subledgerRoleIsDebit = $requiresSubledger && $subledger['role'] === 'debit';
        $savingsId = null;
        $balanceBefore = null;
        $balanceAfter = null;

        if ($requiresSubledger) {
            $memberAccountModel = new MemberSavingsAccountModel();
            $memberAccount = $memberAccountModel->getAccount((int)$voucher['savings_account_id']);
            if (!$memberAccount || $memberAccount['status'] !== 'active') {
                throw new InvalidArgumentException('The member savings account is no longer active.');
            }
            $balanceBefore = $memberAccountModel->getAccountBalance((int)$voucher['savings_account_id']);
            if ($subledgerRoleIsDebit && $amount > $balanceBefore + 0.01) {
                throw new InvalidArgumentException(sprintf(
                    'Debit of Shs %s would take this member\'s savings account below zero (current balance: Shs %s). Posting blocked.',
                    number_format($amount, 2), number_format($balanceBefore, 2)
                ));
            }
            $balanceAfter = $subledgerRoleIsDebit ? $balanceBefore - $amount : $balanceBefore + $amount;
        }

        $lines = $isDebit
            ? [
                ['account_id' => $primaryAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => $voucher['narration']],
                ['account_id' => $contraAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => $voucher['narration']],
            ]
            : [
                ['account_id' => $contraAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => $voucher['narration']],
                ['account_id' => $primaryAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => $voucher['narration']],
            ];

        $voucherLabel = $isDebit ? 'Internal Debit Voucher' : 'Internal Credit Voucher';
        $period = $this->resolvePeriod($voucher['voucher_date']);

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            // Member subsidiary ledger side of the dual-write, written
            // first (mirrors MemberAccountAdjustmentModel::post()'s
            // ordering) — before the GL side, in the same transaction.
            if ($requiresSubledger) {
                $savingsStmt = $this->db->prepare("
                    INSERT INTO `savings`
                        (member_id, savings_account_id, receipt_number, transaction_type, debit, credit,
                         running_balance, description, payment_method, transaction_date, financial_year, notes, recorded_by)
                    VALUES (?, ?, ?, 'adjustment', ?, ?, ?, ?, 'Other', ?, ?, ?, ?)
                ");
                $savingsStmt->execute([
                    $voucher['member_id'], $voucher['savings_account_id'], $voucher['voucher_number'],
                    $subledgerRoleIsDebit ? $amount : 0.00, $subledgerRoleIsDebit ? 0.00 : $amount,
                    $balanceAfter,
                    "Internal Voucher {$voucher['voucher_number']} — {$voucher['narration']}",
                    $voucher['voucher_date'], date('Y', strtotime($voucher['voucher_date'])), $voucher['narration'], $userId,
                ]);
                $savingsId = (int)$this->db->lastInsertId();
            }

            $service = new JournalService();
            $result = $service->post([
                'entry_date'             => $voucher['voucher_date'],
                'description'            => "{$voucherLabel} {$voucher['voucher_number']} — {$voucher['narration']}",
                'source_module'          => 'internal_vouchers',
                'source_reference_type'  => 'voucher',
                'source_reference_id'    => $voucherId,
                'financial_year_id'      => $period['financial_year_id'] ?? null,
                'accounting_period_id'   => $period['period_id'] ?? null,
                'created_by'             => $userId,
                'lines'                  => $lines,
            ]);

            $this->db->prepare(
                "UPDATE `internal_vouchers` SET status = 'posted', posted_at = NOW(), journal_entry_id = ?,
                 savings_id = ?, balance_before = ?, balance_after = ? WHERE id = ?"
            )->execute([$result['id'], $savingsId, $balanceBefore, $balanceAfter, $voucherId]);

            $this->writeAudit($userId, 'posted', $voucherId, [
                'journal_entry_id' => $result['id'],
                'entry_number'     => $result['entry_number'],
                'savings_id'       => $savingsId,
                'balance_before'   => $balanceBefore,
                'balance_after'    => $balanceAfter,
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

        return [
            'journal_entry_id' => $result['id'], 'entry_number' => $result['entry_number'], 'created' => $result['created'],
            'savings_id' => $savingsId, 'balance_before' => $balanceBefore, 'balance_after' => $balanceAfter,
        ];
    }

    /**
     * Same open-period-covering-this-date lookup used by every other
     * posting model — resolves both accounting_period_id and
     * financial_year_id explicitly since JournalService::post() does not
     * auto-derive the latter from the former.
     */
    private function resolvePeriod(string $date): array
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

    /**
     * Read-only preview of the voucher number that WOULD be assigned if a
     * draft were created right now -- does not lock or increment the
     * sequence, purely informational for the create form. The actual
     * number is only reserved (and could differ, if another user submits
     * first) at createDraft() time via nextVoucherNumber().
     */
    public function peekNextVoucherNumber(): string
    {
        $stmt = $this->db->query("SELECT last_number FROM `journal_number_sequences` WHERE prefix = 'IV'");
        $row = $stmt->fetch();
        $next = (int)($row['last_number'] ?? 0) + 1;
        return sprintf('IV-%06d', $next);
    }

    private function nextVoucherNumber(): string
    {
        $stmt = $this->db->prepare("SELECT last_number FROM `journal_number_sequences` WHERE prefix = 'IV' FOR UPDATE");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No journal_number_sequences row for prefix 'IV'.");
        }
        $next = (int)$row['last_number'] + 1;
        $this->db->prepare("UPDATE `journal_number_sequences` SET last_number = ? WHERE prefix = 'IV'")->execute([$next]);
        return sprintf('IV-%06d', $next);
    }

    private function writeAudit(int $userId, string $action, int $voucherId, array $afterData, ?string $reason = null): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `journal_entry_audit` (user_id, action, entity_type, entity_id, before_json, after_json, reason, ip_address)
             VALUES (?, ?, 'internal_voucher', ?, NULL, ?, ?, ?)"
        );
        $stmt->execute([
            $userId,
            $action,
            $voucherId,
            json_encode($afterData),
            $reason,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}
