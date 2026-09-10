<?php
/**
 * SavingsAccountClosureService — Stage 11.
 *
 * Generalizes the governed closure lifecycle Stage FD-2 established
 * (request -> approve/reject/cancel -> settle -> closed, server-side
 * maker-checker, activity_logs audit, JournalService accounting) to the
 * four non-Fixed-Deposit account types: compulsory, voluntary, joint,
 * corporate.
 *
 * Fixed Deposit is deliberately NOT handled here. It keeps using its own
 * certified FixedDepositClosureService/fixed_deposit_closure_requests
 * (Stage FD-2, 51/51 tests, already in production) completely unchanged
 * -- generalizing that table in place would have risked regressing a
 * certified financial workflow for no benefit, since FD's own lifecycle
 * (maturity-gated, fixed contractual payout) is genuinely different from
 * an ordinary account's closure (available any time the account is
 * active, payout is always today's live balance).
 *
 * Key difference from FD-2's payout model: FD's principal+interest are
 * fixed by contract, so FD-2 snapshots the payout amount once at request
 * time and pays exactly that. An ordinary savings balance can keep
 * moving (further deposits/withdrawals) between request and settlement,
 * so this service always recalculates the balance fresh, under lock, at
 * settlement time -- the snapshot taken at request time is for display
 * only, never the amount actually paid.
 */
class SavingsAccountClosureService
{
    private PDO $db;
    private MemberSavingsAccountModel $accountModel;
    private SavingsAccountHolderModel $holderModel;

    public const ACCOUNT_TYPES = ['compulsory', 'voluntary', 'joint', 'corporate'];

    /** Same mapping already duplicated identically in SavingsModel and
     *  FixedDepositClosureService -- this codebase's established
     *  convention is a private per-class copy, not a shared constant. */
    private const PAYMENT_ACCOUNTS = [
        'Cash'               => 7,
        'Airtel Money'       => 8,
        'MTN Mobile Money'   => 8,
        'Bank Transfer'      => 10,
        'Cheque'             => 10,
        'Other'              => 7,
    ];

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->accountModel = new MemberSavingsAccountModel();
        $this->holderModel = new SavingsAccountHolderModel();
    }

    private function log(int $userId, string $action, string $description): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `activity_logs`(`user_id`,`action`,`description`,`ip_address`) VALUES (?,?,?,?)"
        );
        $stmt->execute([$userId ?: null, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    }

    private function findRequest(int $requestId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `savings_account_closure_requests` WHERE id=? FOR UPDATE");
        $stmt->execute([$requestId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException("Closure request id {$requestId} does not exist.");
        }
        return $row;
    }

    private function findAccount(int $accountId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `member_savings_accounts` WHERE id=? FOR UPDATE");
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException("Savings account id {$accountId} does not exist.");
        }
        if (!in_array($row['account_type'], self::ACCOUNT_TYPES, true)) {
            throw new InvalidArgumentException(
                "Account id {$accountId} is a \"{$row['account_type']}\" account -- Fixed Deposit closure uses the separate Stage FD-2 workflow, not this service."
            );
        }
        return $row;
    }

    // ================================================================
    // ELIGIBILITY (read-only)
    // ================================================================

    /**
     * Determines whether $account may have a closure requested right now,
     * and, if so, who the settlement (if any) will be attributed to.
     * Never writes anything. Used both by the request form (to block the
     * button with a clear reason) and by requestClosure() itself (the
     * authoritative re-check).
     *
     * @return array{eligible:bool, reason:?string, settlement_member_id:?int, balance:float}
     */
    public function checkEligibility(array $account, ?int $preferredHolderId = null): array
    {
        if ($account['status'] !== 'active') {
            return ['eligible' => false, 'reason' => "This account is \"{$account['status']}\", not active.", 'settlement_member_id' => null, 'balance' => 0.0];
        }

        $existing = $this->db->prepare(
            "SELECT id FROM `savings_account_closure_requests` WHERE savings_account_id=? AND active_marker=1"
        );
        $existing->execute([$account['id']]);
        if ($existing->fetch()) {
            return ['eligible' => false, 'reason' => 'An active closure request already exists for this account.', 'settlement_member_id' => null, 'balance' => 0.0];
        }

        $balance = $this->accountModel->getAccountBalance((int)$account['id']);

        if ($account['account_type'] === 'corporate') {
            $rep = $this->db->prepare(
                "SELECT r.member_id FROM `organization_representatives`
                 r JOIN `savings_account_holders` h ON h.organization_id = r.organization_id
                 WHERE h.account_id = ? AND h.role = 'organization'
                   AND r.is_authorized_signatory = 1 AND r.member_id IS NOT NULL
                 ORDER BY r.id ASC LIMIT 1"
            );
            $rep->execute([$account['id']]);
            $memberId = $rep->fetchColumn();
            if (!$memberId) {
                return [
                    'eligible' => false,
                    'reason' => 'No authorized representative with a linked member record is on file for this organization -- settlement cannot be attributed. Add one under the organization\'s representatives before requesting closure.',
                    'settlement_member_id' => null,
                    'balance' => $balance,
                ];
            }
            return ['eligible' => true, 'reason' => null, 'settlement_member_id' => (int)$memberId, 'balance' => $balance];
        }

        $holders = $this->holderModel->getAccountHolders((int)$account['id']);
        $memberHolders = array_values(array_filter($holders, fn($h) => !empty($h['member_id'])));
        if (empty($memberHolders)) {
            return ['eligible' => false, 'reason' => 'This account has no valid member holder.', 'settlement_member_id' => null, 'balance' => $balance];
        }

        if ($account['account_type'] === 'joint') {
            if ($preferredHolderId !== null) {
                $match = array_values(array_filter($memberHolders, fn($h) => (int)$h['member_id'] === $preferredHolderId));
                if (empty($match)) {
                    return ['eligible' => false, 'reason' => 'The selected holder is not a holder of this account.', 'settlement_member_id' => null, 'balance' => $balance];
                }
                return ['eligible' => true, 'reason' => null, 'settlement_member_id' => $preferredHolderId, 'balance' => $balance];
            }
            $primary = array_values(array_filter($memberHolders, fn($h) => $h['role'] === 'primary'));
            $default = $primary[0] ?? $memberHolders[0];
            return ['eligible' => true, 'reason' => null, 'settlement_member_id' => (int)$default['member_id'], 'balance' => $balance];
        }

        // compulsory / voluntary -- exactly one holder.
        return ['eligible' => true, 'reason' => null, 'settlement_member_id' => (int)$memberHolders[0]['member_id'], 'balance' => $balance];
    }

    // ================================================================
    // REQUEST
    // ================================================================

    public function requestClosure(int $accountId, int $userId, ?string $reason = null, ?int $holderId = null): int
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) { $this->db->beginTransaction(); }
        try {
            $account = $this->findAccount($accountId);
            $eligibility = $this->checkEligibility($account, $holderId);
            if (!$eligibility['eligible']) {
                throw new InvalidArgumentException($eligibility['reason']);
            }

            $balance = $eligibility['balance'];
            $settlementRequired = $balance > 0.0;

            $stmt = $this->db->prepare(
                "INSERT INTO `savings_account_closure_requests`
                 (savings_account_id, account_type, member_id, reason, settlement_required, settlement_amount,
                  status, active_marker, requested_by, requested_at)
                 VALUES (?,?,?,?,?,?, 'pending', 1, ?, NOW())"
            );
            $stmt->execute([
                $accountId, $account['account_type'], $eligibility['settlement_member_id'],
                $reason !== '' ? $reason : null, $settlementRequired ? 1 : 0, $balance, $userId,
            ]);
            $requestId = (int)$this->db->lastInsertId();

            $this->log($userId, 'savings_account_closure_requested',
                "Closure requested for {$account['account_type']} account {$account['account_number']} — balance Shs "
                . number_format($balance, 2) . " (request #{$requestId}).");

            if ($ownTransaction) { $this->db->commit(); }
            return $requestId;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    // ================================================================
    // APPROVE / REJECT / CANCEL — identical pattern to FixedDepositClosureService
    // ================================================================

    public function approve(int $requestId, int $userId): void
    {
        // Self-approval checked (and, if blocked, logged) BEFORE any
        // transaction opens, so the audit record survives even though the
        // approval itself is rejected — the same fix Stage FD-2 required
        // after finding an in-transaction log write erased by its own
        // rollback.
        $preCheck = $this->db->prepare("SELECT status, requested_by FROM `savings_account_closure_requests` WHERE id=?");
        $preCheck->execute([$requestId]);
        $preCheckRow = $preCheck->fetch();
        if ($preCheckRow && (int)$preCheckRow['requested_by'] === $userId && $preCheckRow['status'] === 'pending') {
            $this->log($userId, 'savings_account_closure_self_approval_blocked',
                "User attempted to approve their own savings account closure request #{$requestId} — blocked.");
            throw new InvalidArgumentException('You cannot approve a closure request you submitted yourself. Ask another approver to review it.');
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) { $this->db->beginTransaction(); }
        try {
            $request = $this->findRequest($requestId);
            if ($request['status'] !== 'pending') {
                throw new InvalidArgumentException("Only a pending request can be approved (current status: {$request['status']}).");
            }
            if ((int)$request['requested_by'] === $userId) {
                throw new InvalidArgumentException('You cannot approve a closure request you submitted yourself. Ask another approver to review it.');
            }

            $this->db->prepare(
                "UPDATE `savings_account_closure_requests` SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?"
            )->execute([$userId, $requestId]);

            $this->log($userId, 'savings_account_closure_approved', "Approved savings account closure request #{$requestId}.");

            if ($ownTransaction) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    public function reject(int $requestId, int $userId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) { $this->db->beginTransaction(); }
        try {
            $request = $this->findRequest($requestId);
            if ($request['status'] !== 'pending') {
                throw new InvalidArgumentException("Only a pending request can be rejected (current status: {$request['status']}).");
            }

            $this->db->prepare(
                "UPDATE `savings_account_closure_requests`
                 SET status='rejected', rejected_by=?, rejected_at=NOW(), rejection_reason=?, active_marker=NULL
                 WHERE id=?"
            )->execute([$userId, $reason, $requestId]);

            $this->log($userId, 'savings_account_closure_rejected', "Rejected savings account closure request #{$requestId}: {$reason}");

            if ($ownTransaction) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /** The requester's own pending request only. */
    public function cancel(int $requestId, int $userId): void
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) { $this->db->beginTransaction(); }
        try {
            $request = $this->findRequest($requestId);
            if ($request['status'] !== 'pending') {
                throw new InvalidArgumentException("Only a pending request can be cancelled (current status: {$request['status']}).");
            }
            if ((int)$request['requested_by'] !== $userId) {
                throw new InvalidArgumentException('You can only cancel a closure request you submitted yourself.');
            }

            $this->db->prepare(
                "UPDATE `savings_account_closure_requests` SET status='cancelled', cancelled_by=?, cancelled_at=NOW(), active_marker=NULL WHERE id=?"
            )->execute([$userId, $requestId]);

            $this->log($userId, 'savings_account_closure_cancelled', "Cancelled savings account closure request #{$requestId}.");

            if ($ownTransaction) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    // ================================================================
    // SETTLE — cashier/admin. Handles both "pay out the balance" and
    // "zero balance, nothing to pay" in one atomic operation.
    // ================================================================

    /**
     * Recalculates the balance fresh, under lock -- never trusts the
     * settlement_amount snapshotted at request time, since (unlike Fixed
     * Deposit's fixed contractual payout) an ordinary account's balance
     * can keep moving via ordinary deposits/withdrawals right up until
     * closure. If the fresh balance is 0, no journal is posted and the
     * account is simply closed. $paymentMethod/$paymentReference are
     * ignored (and may be null) when no settlement is required.
     */
    public function settle(int $requestId, int $userId, ?string $paymentMethod, ?string $paymentReference, string $effectiveDate): array
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) { $this->db->beginTransaction(); }
        try {
            $request = $this->findRequest($requestId);
            if ($request['status'] === 'paid') {
                if ($ownTransaction) { $this->db->commit(); }
                return ['already_paid' => true, 'journal_entry_id' => $request['journal_entry_id']];
            }
            if ($request['status'] !== 'approved') {
                throw new InvalidArgumentException("Only an approved request can be settled (current status: {$request['status']}).");
            }

            $account = $this->findAccount((int)$request['savings_account_id']);
            if ($account['status'] === 'closed') {
                throw new InvalidArgumentException('This account is already closed.');
            }
            if ($account['status'] !== 'active') {
                throw new InvalidArgumentException("This account is \"{$account['status']}\", not active.");
            }

            $balance = $this->accountModel->getAccountBalance((int)$account['id']);
            $journalEntryId = null;
            $entryNumber = null;
            $amountPaid = 0.0;

            if ($balance > 0.0) {
                if (!$paymentMethod || !array_key_exists($paymentMethod, self::PAYMENT_ACCOUNTS)) {
                    throw new InvalidArgumentException('A valid payment method is required to settle this account\'s remaining balance.');
                }

                $savingsModel = new SavingsModel();
                $withdrawal = $savingsModel->recordWithdrawalWithPosting([
                    'member_id'          => (int)$request['member_id'],
                    'savings_account_id' => (int)$account['id'],
                    'amount'             => $balance,
                    'payment_method'     => $paymentMethod,
                    'reference_number'   => $paymentReference ?: null,
                    'transaction_date'   => $effectiveDate,
                    'receipt_number'     => $savingsModel->generateReceiptNumber(),
                    'recorded_by'        => $userId,
                    'notes'              => "Account closure settlement (request #{$requestId})",
                ], $userId);

                $journalEntryId = $withdrawal['journal_entry_id'];
                $entryNumber = $withdrawal['entry_number'];
                $amountPaid = $balance;
            }

            $this->db->prepare(
                "UPDATE `savings_account_closure_requests`
                 SET status='paid', payment_method=?, payment_reference=?, amount_paid=?, paid_by=?, paid_at=NOW(),
                     journal_entry_id=?, closed_at=NOW(), active_marker=NULL
                 WHERE id=?"
            )->execute([$paymentMethod, $paymentReference ?: null, $amountPaid, $userId, $journalEntryId, $requestId]);

            $this->accountModel->closeAccount((int)$account['id'], $effectiveDate);

            if ($amountPaid > 0.0) {
                $this->log($userId, 'savings_account_settled',
                    "Settled {$account['account_type']} account {$account['account_number']} — Shs "
                    . number_format($amountPaid, 2) . " ({$paymentMethod}), journal entry {$entryNumber} (request #{$requestId}).");
            } else {
                $this->log($userId, 'savings_account_settled',
                    "Closed {$account['account_type']} account {$account['account_number']} — zero balance, no settlement required (request #{$requestId}).");
            }
            $this->log($userId, 'savings_account_closed',
                "Account {$account['account_number']} closed following settlement (request #{$requestId}).");

            if ($ownTransaction) { $this->db->commit(); }

            return ['journal_entry_id' => $journalEntryId, 'entry_number' => $entryNumber, 'amount_paid' => $amountPaid];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }
}
