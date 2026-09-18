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

    /** Subledger types this model knows how to dual-write for. Savings and Shares. */
    private const SUPPORTED_SUBLEDGER_TYPES = ['savings', 'shares'];

    private AccountModel $accountModel;

    /**
     * Get the current share value from settings.
     */
    private function getShareValue(): float
    {
        return (float)(new SettingsModel())->get('share_value', '20000');
    }

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
     * neither account requires one. Returns array with 'dual'=>true when
     * BOTH accounts require subledgers (Member-to-Member savings transfers).
     */
    private function resolveSubledgerSide(array $primaryAccount, array $contraAccount, string $voucherType): ?array
    {
        $primaryRequires = $this->accountRequiresSubledger($primaryAccount);
        $contraRequires  = $this->accountRequiresSubledger($contraAccount);

        if ($primaryRequires && $contraRequires) {
            // DUAL SUBLEDGER case: Both sides need member+savings account (e.g., Member A → Member B)
            $isDebitVoucher = $voucherType === 'debit';
            return [
                'dual' => true,
                'primary' => [
                    'account' => $primaryAccount,
                    'role' => $isDebitVoucher ? 'debit' : 'credit',
                    'subledger_type' => $primaryAccount['subledger_type'] ?? null,
                ],
                'contra' => [
                    'account' => $contraAccount,
                    'role' => $isDebitVoucher ? 'credit' : 'debit',
                    'subledger_type' => $contraAccount['subledger_type'] ?? null,
                ],
            ];
        }
        if (!$primaryRequires && !$contraRequires) {
            return null;
        }

        $isDebitVoucher = $voucherType === 'debit';
        if ($primaryRequires) {
            // Debit Voucher: Dr primary. Credit Voucher: Cr primary.
            return ['account' => $primaryAccount, 'role' => $isDebitVoucher ? 'debit' : 'credit', 'subledger_type' => $primaryAccount['subledger_type'] ?? null];
        }
        // contraRequires: Debit Voucher: Cr contra. Credit Voucher: Dr contra.
        return ['account' => $contraAccount, 'role' => $isDebitVoucher ? 'credit' : 'debit', 'subledger_type' => $contraAccount['subledger_type'] ?? null];
    }

    /**
     * Validate and extract savings subledger data (member + savings account).
     * Returns array with member_id, savings_account_id, and current_balance.
     */
    private function validateSavingsSubledger(array $data, string $role, bool $isPrimary, string $label): array
    {
        $memberIdKey = $isPrimary ? 'member_id' : 'contra_member_id';
        $savingsAccountIdKey = $isPrimary ? 'savings_account_id' : 'contra_savings_account_id';
        
        $memberId = (int)($data[$memberIdKey] ?? 0);
        if ($memberId < 1 || !(new MemberModel())->find($memberId)) {
            throw new InvalidArgumentException("Select a valid member for the $label savings account.");
        }

        $savingsAccountId = (int)($data[$savingsAccountIdKey] ?? 0);
        $memberAccountModel = new MemberSavingsAccountModel();
        $memberAccount = $memberAccountModel->getAccount($savingsAccountId);
        if (!$memberAccount) {
            throw new InvalidArgumentException("Select a valid member savings account for the $label side.");
        }
        if ($memberAccount['account_type'] === 'corporate') {
            throw new InvalidArgumentException('Corporate savings accounts are not supported by this workflow.');
        }
        if ($memberAccount['status'] !== 'active') {
            throw new InvalidArgumentException("The $label member savings account is not active.");
        }
        
        $holderIds = array_map(
            fn($h) => (int)$h['member_id'],
            array_filter((new SavingsAccountHolderModel())->getAccountHolders($savingsAccountId), fn($h) => !empty($h['member_id']))
        );
        if (!in_array($memberId, $holderIds, true)) {
            throw new InvalidArgumentException("The $label savings account does not belong to the selected member.");
        }

        $currentBalance = $memberAccountModel->getAccountBalance($savingsAccountId);
        
        return [
            'member_id' => $memberId,
            'savings_account_id' => $savingsAccountId,
            'current_balance' => $currentBalance
        ];
    }

    /**
     * Validate and extract shares subledger data (member only, no account needed).
     * Returns array with share_member_id and current_quantity.
     */
    private function validateSharesSubledger(array $data, string $role, bool $isPrimary, string $label, float $shareValue): array
    {
        $shareMemberIdKey = $isPrimary ? 'share_member_id' : 'contra_share_member_id';
        
        $shareMemberId = (int)($data[$shareMemberIdKey] ?? 0);
        if ($shareMemberId < 1 || !(new MemberModel())->find($shareMemberId)) {
            // Include debug info in the error message
            $debug = "DEBUG: Looking for key='$shareMemberIdKey', found value='$shareMemberId', isPrimary=" . ($isPrimary ? 'true' : 'false') . ", available keys=" . implode(',', array_keys($data));
            throw new InvalidArgumentException("Select a valid member for the $label shares account. [$debug]");
        }

        // Get current share quantity for this member
        $shareModel = new ShareModel();
        $currentQuantity = $shareModel->memberQuantity($shareMemberId, $shareValue);
        
        return [
            'share_member_id' => $shareMemberId,
            'current_quantity' => $currentQuantity
        ];
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
        // Member subledger validation — savings AND shares supported.
        // For savings: validates member_id + savings_account_id
        // For shares: validates share_member_id (no account needed)
        // Both support single-sided and dual (cross-member) scenarios.
        $memberId = null;
        $savingsAccountId = null;
        $contraMemberId = null;
        $contraSavingsAccountId = null;
        $shareMemberId = null;
        $contraShareMemberId = null;

        $subledger = $this->resolveSubledgerSide($primaryAccount, $contraAccount, $data['voucher_type']);
        if ($subledger !== null) {
            $isDual = !empty($subledger['dual']);
            $shareValue = $this->getShareValue();

            if ($isDual) {
                // DUAL SUBLEDGER: Both sides need member tracking
                $primaryType = $subledger['primary']['account']['subledger_type'];
                $contraType = $subledger['contra']['account']['subledger_type'];
                
                // Validate PRIMARY side
                if (!in_array($primaryType, self::SUPPORTED_SUBLEDGER_TYPES, true)) {
                    throw new RuntimeException("Primary account has unsupported subledger_type \"{$primaryType}\".");
                }
                
                if ($primaryType === 'savings') {
                    $primaryData = $this->validateSavingsSubledger($data, $subledger['primary']['role'], true, 'primary');
                    $memberId = $primaryData['member_id'];
                    $savingsAccountId = $primaryData['savings_account_id'];
                    
                    // Internal vouchers can take accounts below zero (e.g., year-end transfers from Compulsory)
                    // No balance check here - just validate the account exists and is active
                } elseif ($primaryType === 'shares') {
                    $primaryData = $this->validateSharesSubledger($data, $subledger['primary']['role'], true, 'primary', $shareValue);
                    $shareMemberId = $primaryData['share_member_id'];
                    
                    if ($subledger['primary']['role'] === 'debit') {
                        $requiredQuantity = $shareValue > 0 ? ((float)$data['amount'] / $shareValue) : 0;
                        if ($requiredQuantity > $primaryData['current_quantity'] + 0.0001) {
                            throw new InvalidArgumentException(sprintf(
                                'Debit of Shs %s requires %.4f shares but member only has %.4f shares.',
                                number_format((float)$data['amount'], 2), $requiredQuantity, $primaryData['current_quantity']
                            ));
                        }
                    }
                }
                
                // Validate CONTRA side
                if (!in_array($contraType, self::SUPPORTED_SUBLEDGER_TYPES, true)) {
                    throw new RuntimeException("Contra account has unsupported subledger_type \"{$contraType}\".");
                }
                
                if ($contraType === 'savings') {
                    $contraData = $this->validateSavingsSubledger($data, $subledger['contra']['role'], false, 'contra');
                    $contraMemberId = $contraData['member_id'];
                    $contraSavingsAccountId = $contraData['savings_account_id'];
                    
                    // Internal vouchers can take accounts below zero (e.g., year-end transfers from Compulsory)
                    // No balance check here - just validate the account exists and is active
                } elseif ($contraType === 'shares') {
                    $contraData = $this->validateSharesSubledger($data, $subledger['contra']['role'], false, 'contra', $shareValue);
                    $contraShareMemberId = $contraData['share_member_id'];
                    
                    if ($subledger['contra']['role'] === 'debit') {
                        $requiredQuantity = $shareValue > 0 ? ((float)$data['amount'] / $shareValue) : 0;
                        if ($requiredQuantity > $contraData['current_quantity'] + 0.0001) {
                            throw new InvalidArgumentException(sprintf(
                                'Debit of Shs %s requires %.4f shares but contra member only has %.4f shares.',
                                number_format((float)$data['amount'], 2), $requiredQuantity, $contraData['current_quantity']
                            ));
                        }
                    }
                }
                
                // Prevent identical source/destination
                if ($primaryType === $contraType) {
                    if ($primaryType === 'savings' && $memberId === $contraMemberId && $savingsAccountId === $contraSavingsAccountId) {
                        throw new InvalidArgumentException('Cannot transfer from and to the same member savings account.');
                    }
                    if ($primaryType === 'shares' && $shareMemberId === $contraShareMemberId) {
                        throw new InvalidArgumentException('Cannot transfer shares to the same member.');
                    }
                }

            } else {
                // SINGLE SUBLEDGER: Only one side requires member tracking
                $subledgerType = $subledger['account']['subledger_type'];
                if (!in_array($subledgerType, self::SUPPORTED_SUBLEDGER_TYPES, true)) {
                    throw new RuntimeException("Account has unsupported subledger_type \"{$subledgerType}\".");
                }

                if ($subledgerType === 'savings') {
                    $subledgerData = $this->validateSavingsSubledger($data, $subledger['role'], true, 'selected');
                    $memberId = $subledgerData['member_id'];
                    $savingsAccountId = $subledgerData['savings_account_id'];
                    
                    // Internal vouchers can take accounts below zero (e.g., year-end transfers from Compulsory)
                    // No balance check here - just validate the account exists and is active
                } elseif ($subledgerType === 'shares') {
                    $subledgerData = $this->validateSharesSubledger($data, $subledger['role'], true, 'selected', $shareValue);
                    $shareMemberId = $subledgerData['share_member_id'];
                    
                    if ($subledger['role'] === 'debit') {
                        $requiredQuantity = $shareValue > 0 ? ((float)$data['amount'] / $shareValue) : 0;
                        if ($requiredQuantity > $subledgerData['current_quantity'] + 0.0001) {
                            throw new InvalidArgumentException(sprintf(
                                'Debit of Shs %s requires %.4f shares but member only has %.4f shares.',
                                number_format((float)$data['amount'], 2), $requiredQuantity, $subledgerData['current_quantity']
                            ));
                        }
                    }
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
                'voucher_number'           => $voucherNumber,
                'voucher_type'             => $data['voucher_type'],
                'voucher_date'             => $data['voucher_date'],
                'primary_account_id'       => $primaryAccountId,
                'contra_account_id'        => $data['contra_account_id'],
                'member_id'                => $memberId,
                'savings_account_id'       => $savingsAccountId,
                'contra_member_id'         => $contraMemberId,
                'contra_savings_account_id'=> $contraSavingsAccountId,
                'share_member_id'          => $shareMemberId,
                'contra_share_member_id'   => $contraShareMemberId,
                'expense_category_id'      => $expenseCategoryId,
                'narration'                => trim($data['narration']),
                'amount'                   => $data['amount'],
                'status'                   => 'draft',
                'recorded_by'              => $userId,
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
                'journal_entry_id'       => (int)$voucher['journal_entry_id'],
                'entry_number'           => $je->fetchColumn(),
                'created'                => false,
                'savings_id'             => $voucher['savings_id'] !== null ? (int)$voucher['savings_id'] : null,
                'balance_before'         => $voucher['balance_before'],
                'balance_after'          => $voucher['balance_after'],
                'contra_savings_id'      => $voucher['contra_savings_id'] ?? null,
                'contra_balance_before'  => $voucher['contra_balance_before'] ?? null,
                'contra_balance_after'   => $voucher['contra_balance_after'] ?? null,
                'share_transaction_id'       => $voucher['share_transaction_id'] ?? null,
                'share_quantity_before'      => $voucher['share_quantity_before'] ?? null,
                'share_quantity_after'       => $voucher['share_quantity_after'] ?? null,
                'contra_share_transaction_id'     => $voucher['contra_share_transaction_id'] ?? null,
                'contra_share_quantity_before'    => $voucher['contra_share_quantity_before'] ?? null,
                'contra_share_quantity_after'     => $voucher['contra_share_quantity_after'] ?? null,
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

        // Member subledger re-validation — savings and shares. Time
        // has passed since createDraft(), so status/balance/quantity are re-checked
        // fresh; ownership is not re-checked (cannot drift post-creation).
        // The GL $lines below are always built from voucher_type/primary/
        // contra as before — only the subsidiary ledger row direction depends on
        // which side (if either) is flagged, via the resolved role.
        $subledger = $this->resolveSubledgerSide($primaryAccount, $contraAccount, $voucher['voucher_type']);
        $requiresSubledger = $subledger !== null;
        $isDual = $requiresSubledger && !empty($subledger['dual']);
        
        $subledgerRoleIsDebit = false;
        $savingsId = null;
        $balanceBefore = null;
        $balanceAfter = null;
        $contraSavingsId = null;
        $contraBalanceBefore = null;
        $contraBalanceAfter = null;

        if ($requiresSubledger) {
            // Determine subledger type(s)
            $primarySubledgerType = !empty($subledger['primary']) ? $subledger['primary']['subledger_type'] : $subledger['subledger_type'];
            $contraSubledgerType = !empty($subledger['contra']) ? $subledger['contra']['subledger_type'] : null;
            
            $memberAccountModel = new MemberSavingsAccountModel();
            $shareModel = new ShareModel();

            if ($isDual) {
                // DUAL SUBLEDGER: Validate BOTH member accounts
                $primaryRoleIsDebit = $subledger['primary']['role'] === 'debit';
                $contraRoleIsDebit = $subledger['contra']['role'] === 'debit';

                // PRIMARY side validation
                if ($primarySubledgerType === 'shares') {
                    // Validate shares quantity
                    $shareValue = $this->getShareValue();
                    $requiredQuantity = $amount / $shareValue;
                    $currentQuantity = $shareModel->memberQuantity((int)$voucher['share_member_id'], $shareValue);
                    if ($primaryRoleIsDebit && $requiredQuantity > $currentQuantity + 0.0001) {
                        throw new InvalidArgumentException(sprintf(
                            'Transfer out of %s shares would exceed the primary member\'s current share balance (%.4f shares available).',
                            number_format($requiredQuantity, 4), $currentQuantity
                        ));
                    }
                } else {
                    // Validate savings account
                    $memberAccount = $memberAccountModel->getAccount((int)$voucher['savings_account_id']);
                    if (!$memberAccount || $memberAccount['status'] !== 'active') {
                        throw new InvalidArgumentException('The primary member savings account is no longer active.');
                    }
                    $balanceBefore = $memberAccountModel->getAccountBalance((int)$voucher['savings_account_id']);
                    if ($primaryRoleIsDebit && $amount > $balanceBefore + 0.01) {
                        throw new InvalidArgumentException(sprintf(
                            'Debit of Shs %s would take the primary member\'s savings account below zero (current balance: Shs %s).',
                            number_format($amount, 2), number_format($balanceBefore, 2)
                        ));
                    }
                    $balanceAfter = $primaryRoleIsDebit ? $balanceBefore - $amount : $balanceBefore + $amount;
                }

                // CONTRA side validation
                if ($contraSubledgerType === 'shares') {
                    // Validate shares quantity
                    $shareValue = $this->getShareValue();
                    $requiredQuantity = $amount / $shareValue;
                    $currentQuantity = $shareModel->memberQuantity((int)$voucher['contra_share_member_id'], $shareValue);
                    if ($contraRoleIsDebit && $requiredQuantity > $currentQuantity + 0.0001) {
                        throw new InvalidArgumentException(sprintf(
                            'Transfer out of %s shares would exceed the contra member\'s current share balance (%.4f shares available).',
                            number_format($requiredQuantity, 4), $currentQuantity
                        ));
                    }
                } else {
                    // Validate savings account
                    $contraAccount = $memberAccountModel->getAccount((int)$voucher['contra_savings_account_id']);
                    if (!$contraAccount || $contraAccount['status'] !== 'active') {
                        throw new InvalidArgumentException('The contra member savings account is no longer active.');
                    }
                    $contraBalanceBefore = $memberAccountModel->getAccountBalance((int)$voucher['contra_savings_account_id']);
                    if ($contraRoleIsDebit && $amount > $contraBalanceBefore + 0.01) {
                        throw new InvalidArgumentException(sprintf(
                            'Debit of Shs %s would take the contra member\'s savings account below zero (current balance: Shs %s).',
                            number_format($amount, 2), number_format($contraBalanceBefore, 2)
                        ));
                    }
                    $contraBalanceAfter = $contraRoleIsDebit ? $contraBalanceBefore - $amount : $contraBalanceBefore + $amount;
                }

            } else {
                // SINGLE SUBLEDGER validation
                $subledgerRoleIsDebit = $subledger['role'] === 'debit';
                
                if ($primarySubledgerType === 'shares') {
                    // Validate shares quantity
                    $shareValue = $this->getShareValue();
                    $requiredQuantity = $amount / $shareValue;
                    $currentQuantity = $shareModel->memberQuantity((int)$voucher['share_member_id'], $shareValue);
                    if ($subledgerRoleIsDebit && $requiredQuantity > $currentQuantity + 0.0001) {
                        throw new InvalidArgumentException(sprintf(
                            'Debit of %s shares would exceed this member\'s current share balance (%.4f shares available). Posting blocked.',
                            number_format($requiredQuantity, 4), $currentQuantity
                        ));
                    }
                } else {
                    // Validate savings account
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
            }
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
            
            $shareTransactionId = null;
            $shareQuantityBefore = null;
            $shareQuantityAfter = null;
            $contraShareTransactionId = null;
            $contraShareQuantityBefore = null;
            $contraShareQuantityAfter = null;
            
            if ($requiresSubledger) {
                // Determine subledger type(s)
                $primarySubledgerType = !empty($subledger['primary']) ? $subledger['primary']['subledger_type'] : $subledger['subledger_type'];
                $contraSubledgerType = !empty($subledger['contra']) ? $subledger['contra']['subledger_type'] : null;
                
                if ($isDual) {
                    // DUAL SUBLEDGER: Write to BOTH members' accounts
                    
                    // PRIMARY side
                    $primaryRoleIsDebit = $subledger['primary']['role'] === 'debit';
                    
                    if ($primarySubledgerType === 'shares') {
                        // PRIMARY: Shares transaction
                        $shareValue = $this->getShareValue();
                        $quantity = $amount / $shareValue;
                        $shareModel = new ShareModel();
                        $shareQuantityBefore = $shareModel->memberQuantity((int)$voucher['share_member_id'], $shareValue);
                        $shareQuantityAfter = $primaryRoleIsDebit ? $shareQuantityBefore - $quantity : $shareQuantityBefore + $quantity;
                        
                        $transactionType = $primaryRoleIsDebit ? 'transfer_out' : 'transfer_in';
                        
                        $shareStmt = $this->db->prepare("
                            INSERT INTO `share_transactions`
                                (member_id, transaction_type, transaction_date, quantity, share_value, amount,
                                 reference_number, source_reference_type, source_reference_id, journal_entry_id, processed_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'internal_voucher', ?, NULL, ?)
                        ");
                        $shareStmt->execute([
                            $voucher['share_member_id'], $transactionType, $voucher['voucher_date'],
                            $quantity, $shareValue, $amount, $voucher['voucher_number'], $voucherId, $userId
                        ]);
                        $shareTransactionId = (int)$this->db->lastInsertId();
                        
                    } else {
                        // PRIMARY: Savings transaction
                        $savingsStmt = $this->db->prepare("
                            INSERT INTO `savings`
                                (member_id, savings_account_id, receipt_number, transaction_type, debit, credit,
                                 running_balance, description, payment_method, transaction_date, financial_year, notes, recorded_by)
                            VALUES (?, ?, ?, 'transfer_out', ?, ?, ?, ?, 'Other', ?, ?, ?, ?)
                        ");
                        $savingsStmt->execute([
                            $voucher['member_id'], $voucher['savings_account_id'], $voucher['voucher_number'],
                            $primaryRoleIsDebit ? $amount : 0.00, $primaryRoleIsDebit ? 0.00 : $amount,
                            $balanceAfter,
                            "Transfer via Voucher {$voucher['voucher_number']} — {$voucher['narration']}",
                            $voucher['voucher_date'], date('Y', strtotime($voucher['voucher_date'])), $voucher['narration'], $userId,
                        ]);
                        $savingsId = (int)$this->db->lastInsertId();
                    }

                    // CONTRA side
                    $contraRoleIsDebit = $subledger['contra']['role'] === 'debit';
                    
                    if ($contraSubledgerType === 'shares') {
                        // CONTRA: Shares transaction
                        $shareValue = $this->getShareValue();
                        $quantity = $amount / $shareValue;
                        $shareModel = new ShareModel();
                        $contraShareQuantityBefore = $shareModel->memberQuantity((int)$voucher['contra_share_member_id'], $shareValue);
                        $contraShareQuantityAfter = $contraRoleIsDebit ? $contraShareQuantityBefore - $quantity : $contraShareQuantityBefore + $quantity;
                        
                        $transactionType = $contraRoleIsDebit ? 'transfer_out' : 'transfer_in';
                        
                        $contraShareStmt = $this->db->prepare("
                            INSERT INTO `share_transactions`
                                (member_id, transaction_type, transaction_date, quantity, share_value, amount,
                                 reference_number, source_reference_type, source_reference_id, journal_entry_id, processed_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'internal_voucher', ?, NULL, ?)
                        ");
                        $contraShareStmt->execute([
                            $voucher['contra_share_member_id'], $transactionType, $voucher['voucher_date'],
                            $quantity, $shareValue, $amount, $voucher['voucher_number'], $voucherId, $userId
                        ]);
                        $contraShareTransactionId = (int)$this->db->lastInsertId();
                        
                    } else {
                        // CONTRA: Savings transaction
                        $contraSavingsStmt = $this->db->prepare("
                            INSERT INTO `savings`
                                (member_id, savings_account_id, receipt_number, transaction_type, debit, credit,
                                 running_balance, description, payment_method, transaction_date, financial_year, notes, recorded_by)
                            VALUES (?, ?, ?, 'transfer_in', ?, ?, ?, ?, 'Other', ?, ?, ?, ?)
                        ");
                        $contraSavingsStmt->execute([
                            $voucher['contra_member_id'], $voucher['contra_savings_account_id'], $voucher['voucher_number'],
                            $contraRoleIsDebit ? $amount : 0.00, $contraRoleIsDebit ? 0.00 : $amount,
                            $contraBalanceAfter,
                            "Transfer via Voucher {$voucher['voucher_number']} — {$voucher['narration']}",
                            $voucher['voucher_date'], date('Y', strtotime($voucher['voucher_date'])), $voucher['narration'], $userId,
                        ]);
                        $contraSavingsId = (int)$this->db->lastInsertId();
                    }

                } else {
                    // SINGLE SUBLEDGER
                    $subledgerRoleIsDebit = $subledger['role'] === 'debit';
                    
                    if ($primarySubledgerType === 'shares') {
                        // SINGLE: Shares transaction
                        $shareValue = $this->getShareValue();
                        $quantity = $amount / $shareValue;
                        $shareModel = new ShareModel();
                        $shareQuantityBefore = $shareModel->memberQuantity((int)$voucher['share_member_id'], $shareValue);
                        $shareQuantityAfter = $subledgerRoleIsDebit ? $shareQuantityBefore - $quantity : $shareQuantityBefore + $quantity;
                        
                        $transactionType = $subledgerRoleIsDebit ? 'redemption' : 'direct_purchase';
                        
                        $shareStmt = $this->db->prepare("
                            INSERT INTO `share_transactions`
                                (member_id, transaction_type, transaction_date, quantity, share_value, amount,
                                 reference_number, source_reference_type, source_reference_id, journal_entry_id, processed_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'internal_voucher', ?, NULL, ?)
                        ");
                        $shareStmt->execute([
                            $voucher['share_member_id'], $transactionType, $voucher['voucher_date'],
                            $quantity, $shareValue, $amount, $voucher['voucher_number'], $voucherId, $userId
                        ]);
                        $shareTransactionId = (int)$this->db->lastInsertId();
                        
                    } else {
                        // SINGLE: Savings transaction
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
                }
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
                 savings_id = ?, balance_before = ?, balance_after = ?,
                 contra_savings_id = ?, contra_balance_before = ?, contra_balance_after = ?,
                 share_transaction_id = ?, share_quantity_before = ?, share_quantity_after = ?,
                 contra_share_transaction_id = ?, contra_share_quantity_before = ?, contra_share_quantity_after = ?
                 WHERE id = ?"
            )->execute([$result['id'], $savingsId, $balanceBefore, $balanceAfter, 
                        $contraSavingsId, $contraBalanceBefore, $contraBalanceAfter,
                        $shareTransactionId, $shareQuantityBefore, $shareQuantityAfter,
                        $contraShareTransactionId, $contraShareQuantityBefore, $contraShareQuantityAfter,
                        $voucherId]);

            $this->writeAudit($userId, 'posted', $voucherId, [
                'journal_entry_id'       => $result['id'],
                'entry_number'           => $result['entry_number'],
                'savings_id'             => $savingsId,
                'balance_before'         => $balanceBefore,
                'balance_after'          => $balanceAfter,
                'contra_savings_id'      => $contraSavingsId,
                'contra_balance_before'  => $contraBalanceBefore,
                'contra_balance_after'   => $contraBalanceAfter,
                'share_transaction_id'       => $shareTransactionId,
                'share_quantity_before'      => $shareQuantityBefore,
                'share_quantity_after'       => $shareQuantityAfter,
                'contra_share_transaction_id'     => $contraShareTransactionId,
                'contra_share_quantity_before'    => $contraShareQuantityBefore,
                'contra_share_quantity_after'     => $contraShareQuantityAfter,
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
            'journal_entry_id'       => $result['id'],
            'entry_number'           => $result['entry_number'],
            'created'                => $result['created'],
            'savings_id'             => $savingsId,
            'balance_before'         => $balanceBefore,
            'balance_after'          => $balanceAfter,
            'contra_savings_id'      => $contraSavingsId,
            'contra_balance_before'  => $contraBalanceBefore,
            'contra_balance_after'   => $contraBalanceAfter,
            'share_transaction_id'       => $shareTransactionId,
            'share_quantity_before'      => $shareQuantityBefore,
            'share_quantity_after'       => $shareQuantityAfter,
            'contra_share_transaction_id'     => $contraShareTransactionId,
            'contra_share_quantity_before'    => $contraShareQuantityBefore,
            'contra_share_quantity_after'     => $contraShareQuantityAfter,
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
