<?php
/**
 * FixedDepositClosureService — Stage FD-2.
 *
 * Centralizes every Fixed Deposit maturity/closure rule so no two
 * controllers can implement a different version of the same financial
 * rule (per the stage's own §37). Reuses JournalService for the one
 * accounting event (payout); never writes to journal_entries/journal_lines
 * directly.
 *
 * Lifecycle enforced here, and only here:
 *   active --(maturity_date reached)--> matured
 *   matured --(office_admin/admin requests)--> pending
 *   pending --(treasurer/admin approves, requested_by != approved_by)--> approved
 *   pending --(treasurer/admin rejects, reason required)--> rejected
 *   pending --(the requester cancels their own request)--> cancelled
 *   approved --(cashier/admin pays)--> paid, and the FD account --> closed
 *
 * Known, reported blocker (Stage FD-2 audit + this stage's own brief,
 * §16/§46): the Chart of Accounts has no "Fixed Deposit Interest Expense"
 * account today. payout() checks for it by name (never by an invented
 * code) immediately before posting and throws a specific, identifiable
 * exception if it is missing -- the whole payout transaction rolls back,
 * nothing is left partially committed, and the request stays 'approved'.
 */
class FixedDepositClosureService
{
    private PDO $db;
    private MemberSavingsAccountModel $accountModel;

    /** The one accounting concept this payout needs that doesn't exist
     *  yet. Looked up by name, not a hardcoded/invented code -- once the
     *  owner approves and creates an account with this exact name (any
     *  code, following the existing Chart of Accounts numbering
     *  convention), payout() works with no further code change. */
    private const FD_INTEREST_EXPENSE_ACCOUNT_NAME = 'Fixed Deposit Interest Expense';

    /** 2020 Members' Savings -- the same liability account FD-1's opening
     *  deposit already credits; payout debits it back out. Not invented
     *  here -- reused from the already-approved FD-1 accounting flow. */
    private const SAVINGS_LIABILITY_ACCOUNT_ID = 17;

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
        $stmt = $this->db->prepare("SELECT * FROM `fixed_deposit_closure_requests` WHERE id=? FOR UPDATE");
        $stmt->execute([$requestId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException("Closure request id {$requestId} does not exist.");
        }
        return $row;
    }

    private function findFdAccount(int $accountId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `member_savings_accounts` WHERE id=? FOR UPDATE");
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        if (!$row || $row['account_type'] !== 'fixed_deposit') {
            throw new InvalidArgumentException("Account id {$accountId} is not a Fixed Deposit account.");
        }
        return $row;
    }

    // ================================================================
    // REQUEST
    // ================================================================

    /**
     * office_admin/admin only (enforced by the controller's role gate,
     * re-verified nowhere else -- this method assumes the caller has
     * already authorized the action, matching every other model/service
     * in this codebase). Re-fetches the account fresh -- never trusts a
     * client-submitted principal/rate/interest for what gets snapshotted.
     */
    public function requestClosure(int $savingsAccountId, int $userId): int
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) { $this->db->beginTransaction(); }
        try {
            $account = $this->findFdAccount($savingsAccountId);

            if ($account['status'] !== 'matured') {
                throw new InvalidArgumentException('This Fixed Deposit has not yet reached maturity. Closure can only be requested for a matured account.');
            }

            $existing = $this->db->prepare(
                "SELECT id FROM `fixed_deposit_closure_requests` WHERE savings_account_id=? AND active_marker=1"
            );
            $existing->execute([$savingsAccountId]);
            if ($existing->fetch()) {
                throw new InvalidArgumentException('An active closure request already exists for this Fixed Deposit.');
            }

            $principal = (float)$account['principal_amount'];
            $interest  = (float)$account['expected_interest'];
            $payout    = round($principal + $interest, 2);

            $stmt = $this->db->prepare(
                "INSERT INTO `fixed_deposit_closure_requests`
                 (savings_account_id, member_id, principal_amount, interest_rate, expected_interest, payout_amount,
                  status, active_marker, requested_by, requested_at)
                 VALUES (?,?,?,?,?,?, 'pending', 1, ?, NOW())"
            );
            $stmt->execute([
                $savingsAccountId, $this->memberIdFor($savingsAccountId),
                $principal, $account['interest_rate'], $interest, $payout, $userId,
            ]);
            $requestId = (int)$this->db->lastInsertId();

            $this->log($userId, 'fixed_deposit_closure_requested',
                "Closure requested for {$account['account_number']} — principal Shs " . number_format($principal, 2) .
                ", expected payout Shs " . number_format($payout, 2) . " (request #{$requestId}).");

            if ($ownTransaction) { $this->db->commit(); }
            return $requestId;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    private function memberIdFor(int $savingsAccountId): int
    {
        $stmt = $this->db->prepare(
            "SELECT member_id FROM `savings_account_holders` WHERE account_id=? AND role='primary' LIMIT 1"
        );
        $stmt->execute([$savingsAccountId]);
        $memberId = $stmt->fetchColumn();
        if (!$memberId) {
            throw new RuntimeException("Fixed Deposit account id {$savingsAccountId} has no primary holder -- cannot determine its member.");
        }
        return (int)$memberId;
    }

    // ================================================================
    // APPROVE / REJECT / CANCEL
    // ================================================================

    /** treasurer/admin only. Maker-checker: requested_by !== $userId,
     *  enforced here regardless of role, including admin. */
    public function approve(int $requestId, int $userId): void
    {
        // Self-approval is checked, and the attempt logged, BEFORE any
        // transaction opens -- so the audit record of the attempt survives
        // even though the approval itself is rejected (a log write made
        // inside the transaction below would be rolled back by the very
        // exception it's recording, which is exactly the bug this ordering
        // avoids).
        $preCheck = $this->db->prepare("SELECT status, requested_by FROM `fixed_deposit_closure_requests` WHERE id=?");
        $preCheck->execute([$requestId]);
        $preCheckRow = $preCheck->fetch();
        if ($preCheckRow && (int)$preCheckRow['requested_by'] === $userId && $preCheckRow['status'] === 'pending') {
            $this->log($userId, 'fixed_deposit_closure_self_approval_blocked',
                "User attempted to approve their own Fixed Deposit closure request #{$requestId} — blocked.");
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
                // Re-checked under lock as defense-in-depth against a race
                // with the pre-check above; already logged there in the
                // common case, so not logged again here.
                throw new InvalidArgumentException('You cannot approve a closure request you submitted yourself. Ask another approver to review it.');
            }

            $this->db->prepare(
                "UPDATE `fixed_deposit_closure_requests` SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?"
            )->execute([$userId, $requestId]);

            $this->log($userId, 'fixed_deposit_closure_approved', "Approved Fixed Deposit closure request #{$requestId}.");

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
                "UPDATE `fixed_deposit_closure_requests`
                 SET status='rejected', rejected_by=?, rejected_at=NOW(), rejection_reason=?, active_marker=NULL
                 WHERE id=?"
            )->execute([$userId, $reason, $requestId]);

            $this->log($userId, 'fixed_deposit_closure_rejected', "Rejected Fixed Deposit closure request #{$requestId}: {$reason}");

            if ($ownTransaction) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /** The requester's own pending request only -- not a general
     *  admin/treasurer cancellation power. */
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
                "UPDATE `fixed_deposit_closure_requests` SET status='cancelled', cancelled_by=?, cancelled_at=NOW(), active_marker=NULL WHERE id=?"
            )->execute([$userId, $requestId]);

            $this->log($userId, 'fixed_deposit_closure_cancelled', "Cancelled Fixed Deposit closure request #{$requestId}.");

            if ($ownTransaction) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    // ================================================================
    // PAYOUT
    // ================================================================

    /**
     * cashier/admin only. Atomic: verifies request+account state fresh
     * under lock, then posts one balanced journal entry and marks the
     * request paid + the account closed, all in one transaction. The
     * amount paid is ALWAYS request.payout_amount -- never a submitted
     * value. Throws (rolling back everything) if the required interest-
     * expense account does not exist; the request remains 'approved' and
     * nothing else changes.
     */
    public function payout(int $requestId, int $userId, string $paymentMethod, ?string $paymentReference, string $paymentDate): array
    {
        if (!array_key_exists($paymentMethod, self::PAYMENT_ACCOUNTS)) {
            throw new InvalidArgumentException("Invalid payment method \"{$paymentMethod}\".");
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) { $this->db->beginTransaction(); }
        try {
            $request = $this->findRequest($requestId);
            if ($request['status'] === 'paid') {
                // Idempotency: a repeated POST for an already-paid request
                // is a no-op, not an error -- return the existing result
                // rather than attempting to pay again.
                if ($ownTransaction) { $this->db->commit(); }
                return [
                    'already_paid'     => true,
                    'journal_entry_id' => $request['journal_entry_id'],
                ];
            }
            if ($request['status'] !== 'approved') {
                throw new InvalidArgumentException("Only an approved request can be paid out (current status: {$request['status']}).");
            }

            $account = $this->findFdAccount((int)$request['savings_account_id']);
            if ($account['status'] === 'closed') {
                throw new InvalidArgumentException('This Fixed Deposit is already closed.');
            }
            if ($account['status'] !== 'matured') {
                throw new InvalidArgumentException('Fixed Deposit has not yet reached maturity.');
            }

            $principal = (float)$request['principal_amount'];
            $interest  = (float)$request['expected_interest'];
            $payout    = round($principal + $interest, 2);

            // ---- The reported blocker (Stage FD-2 audit / this stage's
            // own §16/§46): looked up by name, never an invented code. ----
            $stmt = $this->db->prepare(
                "SELECT * FROM `accounts` WHERE `name`=? AND `type`='expense' AND `is_active`=1 LIMIT 1"
            );
            $stmt->execute([self::FD_INTEREST_EXPENSE_ACCOUNT_NAME]);
            $interestExpenseAccount = $stmt->fetch();
            if (!$interestExpenseAccount) {
                throw new RuntimeException(
                    'BLOCKER: Fixed Deposit maturity payout accounting requires an approved "' .
                    self::FD_INTEREST_EXPENSE_ACCOUNT_NAME . '" account in the Chart of Accounts. ' .
                    'No suitable existing account was found, and no account code has been invented. ' .
                    'The closure request remains approved and unpaid -- nothing has been changed. ' .
                    'Create and activate this account (following the existing Chart of Accounts numbering ' .
                    'convention) before payout can be completed.'
                );
            }

            $liabilityAccount = (new AccountModel())->findActive(self::SAVINGS_LIABILITY_ACCOUNT_ID);
            if (!$liabilityAccount) {
                throw new InvalidArgumentException('The Members\' Savings liability account does not exist or is inactive.');
            }
            $cashAccount = (new AccountModel())->findActive(self::PAYMENT_ACCOUNTS[$paymentMethod]);
            if (!$cashAccount) {
                throw new InvalidArgumentException("The cash/bank account for payment method \"{$paymentMethod}\" does not exist or is inactive.");
            }

            $lines = [
                ['account_id' => $liabilityAccount['id'], 'debit' => $principal, 'credit' => 0, 'description' => 'Fixed Deposit principal released'],
            ];
            if ($interest > 0) {
                $lines[] = ['account_id' => $interestExpenseAccount['id'], 'debit' => $interest, 'credit' => 0, 'description' => 'Fixed Deposit interest paid'];
            }
            $lines[] = ['account_id' => $cashAccount['id'], 'debit' => 0, 'credit' => $payout, 'description' => $paymentMethod];

            $journal = (new JournalService())->post([
                'entry_date'            => $paymentDate,
                'description'           => "Fixed Deposit maturity payout — {$account['account_number']}",
                'source_module'         => 'savings',
                'source_reference_type' => 'fixed_deposit_payout',
                'source_reference_id'   => $requestId,
                'created_by'            => $userId,
                'lines'                 => $lines,
            ]);

            $this->db->prepare(
                "UPDATE `fixed_deposit_closure_requests`
                 SET status='paid', payment_method=?, payment_reference=?, amount_paid=?, paid_by=?, paid_at=NOW(),
                     journal_entry_id=?, active_marker=NULL
                 WHERE id=?"
            )->execute([$paymentMethod, $paymentReference ?: null, $payout, $userId, $journal['id'], $requestId]);

            $this->db->prepare(
                "UPDATE `member_savings_accounts` SET status='closed', closed_date=? WHERE id=?"
            )->execute([$paymentDate, $account['id']]);

            $this->log($userId, 'fixed_deposit_payout',
                "Paid out Fixed Deposit {$account['account_number']} — Shs " . number_format($payout, 2) .
                " ({$paymentMethod}), journal entry {$journal['entry_number']} (request #{$requestId}).");
            $this->log($userId, 'fixed_deposit_closed',
                "Fixed Deposit {$account['account_number']} closed following payout (request #{$requestId}, journal entry {$journal['entry_number']}).");

            if ($ownTransaction) { $this->db->commit(); }

            return ['journal_entry_id' => $journal['id'], 'entry_number' => $journal['entry_number'], 'amount_paid' => $payout];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }
}
