<?php
/**
 * LoanModel — Loan Register, Calculations & Tracking (Upgraded)
 */
class LoanModel extends Model
{
    protected string $table      = 'loans';
    protected string $primaryKey = 'id';

    // ================================================================
    // LOAN NUMBER GENERATION
    // ================================================================

    public function generateLoanNumber(): string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT MAX(CAST(SUBSTRING(`loan_number`, 5) AS UNSIGNED)) AS max_seq FROM `loans`"
            );
            $stmt->execute();
            $row  = $stmt->fetch();
            $next = (int)($row['max_seq'] ?? 0) + 1;
            return 'LNS-' . str_pad($next, 6, '0', STR_PAD_LEFT);
        } catch (PDOException $e) { return 'LNS-000001'; }
    }

    // ================================================================
    // DISBURSEMENT ACCOUNTING (Step 9)
    // ================================================================

    /** disbursement_method -> credit-side cash/bank GL account id (same mapping as ExpenseModel/SavingsModel) */
    private const PAYMENT_ACCOUNTS = [
        'Cash'              => 7,  // 1110 Cash at Hand
        'MTN Mobile Money'  => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Airtel Money'      => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Bank Transfer'     => 10, // 1140 Bank Accounts
        'Cheque'            => 10, // 1140 Bank Accounts
        'Other'             => 7,  // fallback: Cash at Hand
    ];

    /** Loans to Members receivable account (1180) */
    private const LOANS_RECEIVABLE_ACCOUNT = 14;

    /**
     * Post a loan's disbursement as a journal entry: Dr Loans to Members /
     * Cr <disbursement_method account>, for the actual disbursed principal
     * (loan_amount) only -- never interest, fees, or the full payable total.
     * Idempotent: if this loan already has a journal_entry_id, returns that
     * reference instead of posting again. Safe to call more than once.
     *
     * Deliberately separate from the loan-creation flow itself (see Step 9
     * plan) -- this never rolls back the loan record if posting fails; it
     * is its own atomic, independently retriable unit.
     */
    /** Statuses introduced by Stage 8 that mean "not yet approved for
     *  disbursement" -- checked here (not just in disburse()) so this
     *  single choke point protects both the new disburse() action AND the
     *  pre-existing legacy retry route (postDisbursementAction()) against
     *  ever posting an unapproved loan, regardless of which caller reaches it. */
    private const NOT_YET_APPROVED_STATUSES = ['draft', 'pending_approval', 'rejected'];

    public function postDisbursement(int $loanId, int $userId): array
    {
        $loan = $this->find($loanId);
        if (!$loan) {
            throw new InvalidArgumentException("Loan id {$loanId} does not exist.");
        }
        if (in_array($loan['status'], self::NOT_YET_APPROVED_STATUSES, true)) {
            throw new InvalidArgumentException("Loan {$loan['loan_number']} is in '{$loan['status']}' status and has not been approved for disbursement.");
        }
        if (!empty($loan['journal_entry_id'])) {
            $je = $this->db->prepare("SELECT entry_number FROM journal_entries WHERE id = ?");
            $je->execute([$loan['journal_entry_id']]);
            return ['journal_entry_id' => (int)$loan['journal_entry_id'], 'entry_number' => $je->fetchColumn(), 'created' => false];
        }

        $method = $loan['disbursement_method'] ?: 'Cash';
        $creditAccountId = self::PAYMENT_ACCOUNTS[$method] ?? 7;
        $creditAccount = (new AccountModel())->findActive($creditAccountId);
        if (!$creditAccount) {
            throw new InvalidArgumentException("The cash/bank account for disbursement method \"{$method}\" does not exist or is inactive.");
        }
        $receivableAccount = (new AccountModel())->findActive(self::LOANS_RECEIVABLE_ACCOUNT);
        if (!$receivableAccount) {
            throw new InvalidArgumentException('The Loans to Members receivable account does not exist or is inactive.');
        }

        $disbursementDate = $loan['disbursement_date'] ?: $loan['issue_date'];

        // JournalService::post() auto-resolves accounting_period_id when none
        // is passed, but takes financial_year_id as-is (defaults to NULL) --
        // resolve both explicitly, same fix already made in ExpenseModel and
        // SavingsModel after Step 7 caught this gap via testing.
        $periodStmt = $this->db->prepare("
            SELECT ap.id AS period_id, ap.financial_year_id
            FROM `accounting_periods` ap
            LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
            WHERE ap.status = 'open'
              AND ap.start_date <= ? AND ap.end_date >= ?
              AND (fy.status = 'active' OR fy.status IS NULL)
            LIMIT 1
        ");
        $periodStmt->execute([$disbursementDate, $disbursementDate]);
        $period = $periodStmt->fetch();

        $amount = (float)$loan['loan_amount'];

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $service = new JournalService();
            $result = $service->post([
                'entry_date'             => $disbursementDate,
                'description'            => "Loan disbursement {$loan['loan_number']}",
                'source_module'          => 'loans',
                'source_reference_type'  => 'disbursement',
                'source_reference_id'    => $loanId,
                'financial_year_id'      => $period['financial_year_id'] ?? null,
                'accounting_period_id'   => $period['period_id'] ?? null,
                'created_by'             => $userId,
                'lines'                  => [
                    ['account_id' => $receivableAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Loans to Members'],
                    ['account_id' => $creditAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => $method],
                ],
            ]);

            $this->db->prepare("UPDATE `loans` SET journal_entry_id = ? WHERE id = ?")->execute([$result['id'], $loanId]);

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

    // ================================================================
    // APPROVAL WORKFLOW (Stage 8)
    //
    // Mirrors the proven draft -> pending_approval -> approved/rejected
    // pattern already used by InternalVoucherModel / MemberAccountAdjustmentModel
    // / InvestmentModel / OpeningBalanceBatchModel: status lives on the
    // loan's own row (no separate workflow table, matching precedent),
    // recorded_by is reused as the creator/preparer identity (no new
    // submitted_by column, matching InternalVoucherModel exactly), and
    // creator != approver is enforced here in the model, not the
    // controller, so it can't be bypassed by any future second entry point.
    // ================================================================

    public function submit(int $id, int $userId): void
    {
        $loan = $this->find($id);
        if (!$loan) {
            throw new InvalidArgumentException("Loan id {$id} does not exist.");
        }
        if (!in_array($loan['status'], ['draft', 'rejected'], true)) {
            throw new InvalidArgumentException("Only a draft or rejected loan can be submitted for approval (current status: {$loan['status']}).");
        }

        $this->db->prepare(
            "UPDATE `loans` SET status = 'pending_approval', submitted_at = NOW(),
             rejected_by = NULL, rejected_at = NULL, rejection_reason = NULL WHERE id = ?"
        )->execute([$id]);
    }

    public function approve(int $id, int $userId): void
    {
        $loan = $this->find($id);
        if (!$loan) {
            throw new InvalidArgumentException("Loan id {$id} does not exist.");
        }
        if ($loan['status'] !== 'pending_approval') {
            throw new InvalidArgumentException("Only a pending-approval loan can be approved (current status: {$loan['status']}).");
        }
        if ((int)$loan['recorded_by'] === $userId) {
            throw new InvalidArgumentException('You cannot approve a loan you created yourself. Ask another approver to review it.');
        }

        $this->db->prepare(
            "UPDATE `loans` SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?"
        )->execute([$userId, $id]);
    }

    public function reject(int $id, int $userId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }
        $loan = $this->find($id);
        if (!$loan) {
            throw new InvalidArgumentException("Loan id {$id} does not exist.");
        }
        if ($loan['status'] !== 'pending_approval') {
            throw new InvalidArgumentException("Only a pending-approval loan can be rejected (current status: {$loan['status']}).");
        }

        $this->db->prepare(
            "UPDATE `loans` SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ? WHERE id = ?"
        )->execute([$userId, $reason, $id]);
    }

    /**
     * Disburse an approved loan: posts the identical accounting entry
     * postDisbursement() has always posted (no change to the accounting
     * logic itself, only to *when* it is now reachable from), then moves
     * the loan to 'active'. postDisbursement() independently re-checks
     * status is not one of the not-yet-approved statuses, so this is
     * defense in depth, not the only enforcement point.
     */
    public function disburse(int $id, int $userId): array
    {
        $loan = $this->find($id);
        if (!$loan) {
            throw new InvalidArgumentException("Loan id {$id} does not exist.");
        }
        if ($loan['status'] !== 'approved') {
            throw new InvalidArgumentException("Only an approved loan can be disbursed (current status: {$loan['status']}).");
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $result = $this->postDisbursement($id, $userId);
            $this->db->prepare(
                "UPDATE `loans` SET status = 'active', disbursed_by = ?, disbursed_at = NOW() WHERE id = ?"
            )->execute([$userId, $id]);

            if ($ownTransaction) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Loans awaiting Chairman's decision, for the dashboard's Pending
     *  Approvals widget -- same shape/purpose as the other four modules'
     *  own pendingApproval() methods. */
    public function pendingApproval(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT l.*, m.first_name, m.last_name, m.member_number,
                        u.full_name AS recorded_by_name
                 FROM `loans` l
                 JOIN `members` m ON m.id = l.member_id
                 LEFT JOIN `users` u ON u.id = l.recorded_by
                 WHERE l.status = 'pending_approval'
                 ORDER BY l.submitted_at ASC"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Stage 13-F3 (13E-FIN-02): once a loan's disbursement has been posted
     * to the General Ledger (journal_entry_id set by postDisbursement()),
     * the facts that determine its accounting meaning must not be silently
     * rewritten -- previously LoanController::edit() could resubmit the
     * full loan form against an 'active' (already-disbursed/posted) loan
     * with zero guard, which would not only desync the GL (changing
     * loan_amount after the Dr Loans-to-Members/Cr Cash entry was posted
     * for the OLD amount) but also reset outstanding back to total_payable
     * and amount_paid back to 0 -- silently erasing real repayment history,
     * since collectInput() always recomputes those two fields unconditionally
     * on every submit of that form. This list is every field the edit form
     * unconditionally resubmits (so any edit-form submission against a
     * posted loan is blocked outright, matching intent) plus the other
     * fields that determine the disbursement journal / repayment schedule.
     * Deliberately EXCLUDES status/outstanding/amount_paid/interest_paid_total/
     * penalty fields/next_payment_date/last_payment_date/completed_date and
     * purely descriptive fields (remarks, guarantor/business/contact info) --
     * these are legitimate, ongoing system-tracking fields that real,
     * already-working features must keep updating after posting:
     * markComplete() (status+outstanding) and RepaymentModel (which updates
     * loans directly via SQL, never through this method, and is therefore
     * unaffected either way). "status" is instead separately protected
     * immediately below against being moved BACKWARD into a pre-disbursement
     * state once posted, without blocking its legitimate forward moves.
     */
    private const LOAN_FINANCIAL_FIELDS = [
        'member_id', 'loan_type_id', 'loan_amount', 'approved_amount',
        'interest_rate', 'suggested_interest_rate', 'rate_overridden', 'rate_override_by', 'rate_override_reason',
        'interest_amount', 'interest_mode', 'fixed_interest_amount',
        'processing_fee', 'monthly_installment', 'total_payable',
        'loan_period', 'loan_period_months', 'grace_period_months',
        'repayment_method', 'repayment_frequency', 'repayment_pattern',
        'issue_date', 'due_date', 'approval_date', 'date_approved', 'date_issued',
        'disbursement_date', 'disbursement_method', 'application_id',
    ];

    /** Statuses that mean "not yet disbursed" -- once a loan is posted, its
     *  status must never be moved backward into one of these (would imply
     *  it is safe to re-edit/re-approve/re-disburse an already-posted loan). */
    private const PRE_DISBURSEMENT_STATUSES = ['draft', 'pending_approval', 'approved', 'rejected'];

    public function update(int $id, array $data): bool
    {
        $existing = $this->find($id);
        if ($existing && !empty($existing['journal_entry_id'])) {
            $blocked = array_intersect(self::LOAN_FINANCIAL_FIELDS, array_keys($data));
            if ($blocked) {
                throw new InvalidArgumentException(
                    "Loan {$existing['loan_number']} has already been posted to the General Ledger (disbursement journal " .
                    "entry linked) and its financial details cannot be edited. " .
                    "To correct a posted loan, reverse it through the Controlled Corrections workflow " .
                    "and record the correction as a new entry instead."
                );
            }
            if (array_key_exists('status', $data) && in_array($data['status'], self::PRE_DISBURSEMENT_STATUSES, true)) {
                throw new InvalidArgumentException(
                    "Loan {$existing['loan_number']} has already been posted to the General Ledger and its status " .
                    "cannot be moved back to '{$data['status']}'."
                );
            }
        }

        return parent::update($id, $data);
    }

    /**
     * Delete a loan, reversing its posted disbursement journal first if one
     * exists (Stage 9 — prevents the orphaned-journal problem the old
     * bare-delete path could reproduce). Atomic: if the reversal fails,
     * the loan row is not deleted either.
     *
     * Stage 13-F3 (13E-FIN-02): a POSTED loan (journal_entry_id set) is
     * historical financial evidence and must not be destroyed via this
     * path any more -- reversing the journal keeps the GL balanced, but
     * deleting the loan row itself still erases the one application record
     * that shows what was actually disbursed, and leaves the (correctly
     * balanced) journal entries pointing at a source_reference_id that no
     * longer exists. Unlike SavingsModel::delete(), no internal caller of
     * this method ever needs to delete a POSTED loan (confirmed by forensic
     * trace: LoanController::delete() is the real user-facing action now
     * blocked here; the only other callers -- LoanController::handleSave()'s
     * two compensating deletes -- only ever remove a loan that was just
     * created in the same request and has never been disbursed, so
     * journal_entry_id is always empty for them), so this guard has no
     * opt-in escape hatch at all.
     */
    public function delete(int $id, int $userId = 0): bool
    {
        $loan = $this->find($id);
        if (!$loan) {
            return false;
        }

        if (!empty($loan['journal_entry_id'])) {
            throw new InvalidArgumentException(
                "Loan {$loan['loan_number']} has already been posted to the General Ledger (disbursement journal " .
                "entry linked) and cannot be deleted. " .
                "To correct a posted loan, reverse it through the Controlled Corrections workflow instead."
            );
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            if (!empty($loan['journal_entry_id'])) {
                (new JournalService())->reverse((int)$loan['journal_entry_id'], $userId, 'Loan deleted');
            }
            $stmt = $this->db->prepare("DELETE FROM `loans` WHERE `id` = ?");
            $stmt->execute([$id]);
            $ok = $stmt->rowCount() > 0;

            if ($ownTransaction) {
                $this->db->commit();
            }
            return $ok;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // ================================================================
    // LOAN TYPES
    // ================================================================

    public function getLoanTypes(): array
    {
        try {
            return $this->db->query("SELECT * FROM `loan_types` WHERE `is_active`=1 ORDER BY `name`")->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function getAllLoanTypes(): array
    {
        try {
            return $this->db->query("SELECT * FROM `loan_types` ORDER BY `id`")->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function createLoanType(string $name, string $desc = ''): int|false
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO `loan_types` (`name`,`description`) VALUES (?,?)");
            $stmt->execute([$name, $desc]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) { return false; }
    }

    public function updateLoanType(int $id, string $name, string $desc, int $active): bool
    {
        try {
            $stmt = $this->db->prepare("UPDATE `loan_types` SET `name`=?,`description`=?,`is_active`=? WHERE `id`=?");
            return $stmt->execute([$name, $desc, $active, $id]);
        } catch (PDOException $e) { return false; }
    }

    // ================================================================
    // LOAN CALCULATIONS ENGINE
    // ================================================================

    /**
     * Calculate loan details using the LoanProductModel engine.
     * Delegates to product-specific bracket logic.
     */
    public function calculateLoan(float $loanAmount, int $periodMonths, int $loanTypeId = 1): array
    {
        require_once APP_PATH . '/models/LoanProductModel.php';
        $productModel = new LoanProductModel();
        return $productModel->calculateLoan($loanTypeId, $loanAmount, $periodMonths);
    }

    // ================================================================
    // INSTALLMENT SCHEDULE GENERATION
    // ================================================================

    /**
     * Generate installment schedule after loan approval.
     *
     * $graceMonths (Stage 9 — product-configured grace period, snapshotted
     * onto the loan at creation time as loans.grace_period_months): the
     * first $graceMonths installments defer principal recovery entirely
     * (amount_due = that month's flat interest only, is_grace_period=1);
     * principal is then spread evenly across the remaining
     * ($periodMonths - $graceMonths) installments, on top of their own
     * ongoing interest. Total interest charged over the loan's life is
     * unchanged by grace -- only *when* principal is collected shifts, per
     * the existing flat-interest rule (interest is never waived or
     * recalculated). When $graceMonths is 0 (every product except
     * Start-Up today), behavior is byte-identical to before this change.
     */
    public function generateInstallments(
        int $loanId,
        float $monthlyInstallment,
        int $periodMonths,
        string $startDate,
        int $graceMonths = 0,
        float $monthlyInterest = 0.0,
        float $principal = 0.0
    ): void {
        try {
            // Remove existing installments for this loan (if regenerating)
            $stmt = $this->db->prepare("DELETE FROM `loan_installments` WHERE `loan_id`=?");
            $stmt->execute([$loanId]);

            // Never let a misconfigured grace period consume the entire
            // schedule -- at least one installment must remain to recover
            // principal.
            $graceMonths = max(0, min($graceMonths, $periodMonths - 1));
            $recoveryMonths = $periodMonths - $graceMonths;

            // Older callers (schedule re-print, historical import) only
            // ever pass the flat $monthlyInstallment, not the
            // principal/interest split -- their exact prior per-row
            // behavior (a single undifferentiated amount, no
            // principal_due/interest_due) is preserved untouched, with
            // only the Stage 9.2 rounding-remainder fix layered on top.
            // Callers that do supply the split (the create path, both
            // monthly and Stage 9's grace-period case) get full
            // principal/interest tracking with an exact-sum guarantee.
            $splitKnown = ($monthlyInterest > 0 || $principal > 0);
            $principalPerRecoveryMonth = ($splitKnown && $recoveryMonths > 0)
                ? round($principal / $recoveryMonths, 2)
                : 0.0;

            // Stage 9.2: when the principal/interest split is known, derive
            // total_payable directly as principal + total_interest (one
            // rounding step) rather than $monthlyInstallment * $periodMonths
            // (which double-rounds: $monthlyInstallment was itself already
            // rounded once from total_payable/periodMonths upstream, so
            // multiplying it back out can drift a cent from the loan's own
            // stored total_payable -- exactly the drift this stage exists
            // to eliminate).
            $totalPayable = $splitKnown
                ? round(($monthlyInterest * $periodMonths) + $principal, 2)
                : round($monthlyInstallment * $periodMonths, 2);

            $amountRunning    = 0.0;
            $principalRunning = 0.0;

            for ($i = 1; $i <= $periodMonths; $i++) {
                $dueDate = date('Y-m-d', strtotime("+{$i} months", strtotime($startDate)));
                $monthCovered = date('M Y', strtotime($dueDate));
                $isGrace = $splitKnown && $graceMonths > 0 && $i <= $graceMonths;
                $isLast  = ($i === $periodMonths);

                if ($splitKnown) {
                    $interestDue  = $monthlyInterest;
                    $principalDue = $isGrace ? 0.0 : $principalPerRecoveryMonth;
                    $amountDue    = $isGrace ? $interestDue : round($interestDue + $principalDue, 2);
                } else {
                    $interestDue  = 0.0;
                    $principalDue = 0.0;
                    $amountDue    = $monthlyInstallment;
                }

                // Stage 9.2: the last installment absorbs whatever rounding
                // remainder is left, so SUM(amount_due) == total_payable
                // and SUM(principal_due) == principal exactly, always --
                // never a schedule that drifts a few cents from the loan's
                // actual obligation.
                if ($isLast) {
                    $amountDue = round($totalPayable - $amountRunning, 2);
                    if ($splitKnown && !$isGrace) {
                        $principalDue = round($principal - $principalRunning, 2);
                    }
                }

                $amountRunning += $amountDue;
                if ($splitKnown) { $principalRunning += $principalDue; }
                $remaining = max(0, round($totalPayable - $amountRunning, 2));

                $stmt = $this->db->prepare(
                    "INSERT INTO `loan_installments` (`loan_id`,`installment_no`,`due_date`,`month_covered`,`principal_due`,`interest_due`,`amount_due`,`amount_paid`,`remaining`,`status`,`is_grace_period`)
                     VALUES (?,?,?,?,?,?,?,0,?,'pending',?)"
                );
                $stmt->execute([$loanId, $i, $dueDate, $monthCovered, $principalDue, $interestDue, $amountDue, $remaining, $isGrace ? 1 : 0]);
            }
        } catch (PDOException $e) {
            // loan_installments is currently unreadable at the storage-engine
            // level (see the Stage 7E structural-recovery report) -- surface
            // this rather than silently pretending the schedule was generated.
            error_log('generateInstallments() failed for loan ' . $loanId . ': ' . $e->getMessage());
        }
    }

    /**
     * Stage 9.2 -- the weekly counterpart to generateInstallments(), for
     * percentage-mode standard-installment loans with repayment_frequency
     * = 'weekly'. Previously no such generator existed: every
     * percentage-mode loan, regardless of frequency, fell through to the
     * monthly-only generateInstallments() above, silently ignoring a
     * 'weekly' repayment_frequency. Only fixed-interest-mode weekly loans
     * (generateWeeklyInterestSchedule(), Business Boost) ever got a real
     * weekly schedule.
     *
     * Reuses the exact weeks-per-month convention already established and
     * used by generateWeeklyInterestSchedule() and the loan form's own
     * client-side preview (months x 4) -- not a new rule invented for this
     * fix, but the one already proven elsewhere in this codebase.
     *
     * $graceWeeks: grace period translated into weekly terms
     * (grace months x 4), deferring principal exactly as
     * generateInstallments()'s monthly grace logic does.
     */
    public function generateWeeklyInstallmentSchedule(
        int $loanId,
        float $principal,
        float $totalInterest,
        float $totalPayable,
        int $periodMonths,
        string $startDate,
        int $graceWeeks = 0
    ): void {
        try {
            $stmt = $this->db->prepare("DELETE FROM `loan_installments` WHERE `loan_id`=?");
            $stmt->execute([$loanId]);

            $weeks = max(1, $periodMonths * 4);
            $graceWeeks = max(0, min($graceWeeks, $weeks - 1));
            $recoveryWeeks = $weeks - $graceWeeks;

            $interestPerWeek  = round($totalInterest / $weeks, 2);
            $principalPerWeek = $recoveryWeeks > 0 ? round($principal / $recoveryWeeks, 2) : 0.0;
            $totalPayableRounded = round($totalPayable, 2);

            $amountRunning    = 0.0;
            $principalRunning = 0.0;

            for ($w = 1; $w <= $weeks; $w++) {
                $dueDate = date('Y-m-d', strtotime("+{$w} weeks", strtotime($startDate)));
                $weekCovered = 'Week ' . $w . ' (' . date('d M Y', strtotime($dueDate)) . ')';
                $isGrace = $graceWeeks > 0 && $w <= $graceWeeks;
                $isLast  = ($w === $weeks);

                $interestDue  = $interestPerWeek;
                $principalDue = $isGrace ? 0.0 : $principalPerWeek;
                $amountDue    = $isGrace ? $interestDue : round($interestDue + $principalDue, 2);

                // Same exact-sum guarantee as generateInstallments(): the
                // last installment absorbs the rounding remainder.
                if ($isLast) {
                    $amountDue = round($totalPayableRounded - $amountRunning, 2);
                    if (!$isGrace) {
                        $principalDue = round($principal - $principalRunning, 2);
                    }
                }

                $amountRunning += $amountDue;
                $principalRunning += $principalDue;
                $remaining = max(0, round($totalPayableRounded - $amountRunning, 2));

                $stmt = $this->db->prepare(
                    "INSERT INTO `loan_installments` (`loan_id`,`installment_no`,`period_type`,`due_date`,`month_covered`,`principal_due`,`interest_due`,`amount_due`,`amount_paid`,`remaining`,`status`,`is_grace_period`)
                     VALUES (?,?,'weekly',?,?,?,?,?,0,?,'pending',?)"
                );
                $stmt->execute([$loanId, $w, $dueDate, $weekCovered, $principalDue, $interestDue, $amountDue, $remaining, $isGrace ? 1 : 0]);
            }
        } catch (PDOException $e) {
            error_log('generateWeeklyInstallmentSchedule() failed for loan ' . $loanId . ': ' . $e->getMessage());
        }
    }

    /**
     * Generate WEEKLY interest-only installment schedule.
     * Used for loans with repayment_frequency = 'weekly' and interest_mode = 'fixed'.
     *
     * Structure:
     *   - First (periodMonths - 2) months: weekly interest-only payments
     *   - Last 2 months (8 weeks): weekly principal recovery + interest
     */
    public function generateWeeklyInterestSchedule(int $loanId, float $principal, float $weeklyInterest, int $periodMonths, string $startDate): void
    {
        try {
            // Remove existing installments
            $stmt = $this->db->prepare("DELETE FROM `loan_installments` WHERE `loan_id`=?");
            $stmt->execute([$loanId]);

            $interestOnlyMonths = max(1, $periodMonths - 2);
            $interestOnlyWeeks = $interestOnlyMonths * 4;
            $recoveryWeeks = 8; // last 2 months
            $weeklyPrincipal = round($principal / $recoveryWeeks, 2);
            $balance = $principal;
            $installmentNo = 0;

            // ── Phase 1: Interest-only weekly payments ──
            for ($w = 1; $w <= $interestOnlyWeeks; $w++) {
                $installmentNo++;
                $dueDate = date('Y-m-d', strtotime("+{$w} weeks", strtotime($startDate)));
                $weekCovered = 'Week ' . $w . ' (' . date('d M Y', strtotime($dueDate)) . ')';

                $stmt = $this->db->prepare(
                    "INSERT INTO `loan_installments`
                     (`loan_id`,`installment_no`,`period_type`,`payment_type`,`due_date`,`month_covered`,
                      `principal_due`,`interest_due`,`amount_due`,`amount_paid`,`remaining`,`balance_after`,`status`)
                     VALUES (?,?,'weekly','interest_only',?,?,0,?,?,0,?,?,'pending')"
                );
                $stmt->execute([
                    $loanId, $installmentNo, $dueDate, $weekCovered,
                    $weeklyInterest, $weeklyInterest, $balance, $balance
                ]);
            }

            // ── Phase 2: Principal recovery + interest (last 8 weeks) ──
            $recoveryStartDate = date('Y-m-d', strtotime("+{$interestOnlyWeeks} weeks", strtotime($startDate)));

            for ($w = 1; $w <= $recoveryWeeks; $w++) {
                $installmentNo++;
                $dueDate = date('Y-m-d', strtotime("+{$w} weeks", strtotime($recoveryStartDate)));
                $weekCovered = 'Week ' . ($interestOnlyWeeks + $w) . ' (' . date('d M Y', strtotime($dueDate)) . ')';

                // Last week: clear exact remaining balance
                $actualPrincipal = ($w === $recoveryWeeks) ? $balance : $weeklyPrincipal;
                $totalDue = $actualPrincipal + $weeklyInterest;
                $balance = round(max(0, $balance - $actualPrincipal), 2);

                $stmt = $this->db->prepare(
                    "INSERT INTO `loan_installments`
                     (`loan_id`,`installment_no`,`period_type`,`payment_type`,`due_date`,`month_covered`,
                      `principal_due`,`interest_due`,`amount_due`,`amount_paid`,`remaining`,`balance_after`,`status`)
                     VALUES (?,?,'weekly','principal_interest',?,?,?,?,?,0,?,?,'pending')"
                );
                $stmt->execute([
                    $loanId, $installmentNo, $dueDate, $weekCovered,
                    $actualPrincipal, $weeklyInterest, $totalDue, $balance, $balance
                ]);
            }

        } catch (PDOException $e) {
            error_log('generateWeeklyInterestSchedule() failed for loan ' . $loanId . ': ' . $e->getMessage());
        }
    }

    /**
     * Get installments for a loan.
     */
    public function getInstallments(int $loanId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM `loan_installments` WHERE `loan_id`=? ORDER BY `installment_no` ASC"
            );
            $stmt->execute([$loanId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    /**
     * Update installments when a repayment is made.
     */
    public function updateInstallmentsOnPayment(int $loanId, float $amountPaid): void
    {
        try {
            // Get pending/partial installments ordered by due date
            $stmt = $this->db->prepare(
                "SELECT * FROM `loan_installments` WHERE `loan_id`=? AND `status` IN('pending','partial','overdue')
                 ORDER BY `installment_no` ASC"
            );
            $stmt->execute([$loanId]);
            $installments = $stmt->fetchAll();

            $remaining = $amountPaid;

            foreach ($installments as $inst) {
                if ($remaining <= 0) break;

                $owed = (float)$inst['amount_due'] - (float)$inst['amount_paid'];
                if ($owed <= 0) continue;

                $pay = min($remaining, $owed);
                $newPaid = (float)$inst['amount_paid'] + $pay;
                $newStatus = ($newPaid >= (float)$inst['amount_due']) ? 'paid' : 'partial';
                $paidDate = ($newStatus === 'paid') ? date('Y-m-d') : null;

                $upd = $this->db->prepare(
                    "UPDATE `loan_installments` SET `amount_paid`=?, `status`=?, `paid_date`=?,
                     `remaining`=GREATEST(0, `amount_due` - ?) WHERE `id`=?"
                );
                $upd->execute([$newPaid, $newStatus, $paidDate, $newPaid, $inst['id']]);

                $remaining -= $pay;
            }
        } catch (PDOException $e) {}
    }

    /**
     * Get current installment number and remaining count.
     */
    public function getInstallmentProgress(int $loanId): array
    {
        try {
            $total = (int)$this->db->prepare("SELECT COUNT(*) FROM `loan_installments` WHERE `loan_id`=?")->execute([$loanId]) ?
                (int)$this->db->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;

            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `loan_installments` WHERE `loan_id`=?");
            $stmt->execute([$loanId]);
            $total = (int)$stmt->fetchColumn();

            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `loan_installments` WHERE `loan_id`=? AND `status`='paid'");
            $stmt->execute([$loanId]);
            $paid = (int)$stmt->fetchColumn();

            $stmt = $this->db->prepare(
                "SELECT `installment_no` FROM `loan_installments` WHERE `loan_id`=? AND `status` IN('pending','partial','overdue')
                 ORDER BY `installment_no` ASC LIMIT 1"
            );
            $stmt->execute([$loanId]);
            $current = $stmt->fetchColumn();

            return [
                'total'     => $total,
                'paid'      => $paid,
                'remaining' => $total - $paid,
                'current'   => $current ?: ($paid + 1),
            ];
        } catch (PDOException $e) {
            return ['total' => 0, 'paid' => 0, 'remaining' => 0, 'current' => 1];
        }
    }

    // ================================================================
    // SYNC OVERDUE INSTALLMENTS
    // ================================================================

    public function syncOverdueInstallments(): void
    {
        try {
            $this->db->exec(
                "UPDATE `loan_installments` SET `status`='overdue'
                 WHERE `status` IN('pending','partial') AND `due_date` < CURDATE()"
            );
        } catch (PDOException $e) {}
    }

    // ================================================================
    // DASHBOARD & STATS
    // ================================================================

    public function countActive(): int
    {
        try { return (int)$this->db->query("SELECT COUNT(*) FROM `loans` WHERE status='active'")->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    public function countOverdue(): int
    {
        try { return (int)$this->db->query("SELECT COUNT(*) FROM `loans` WHERE status='overdue' OR (status='active' AND due_date < CURDATE())")->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    public function countCompleted(): int
    {
        try { return (int)$this->db->query("SELECT COUNT(*) FROM `loans` WHERE status='completed'")->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    public function totalOutstanding(): float
    {
        try { return (float)$this->db->query("SELECT COALESCE(SUM(outstanding),0) FROM `loans` WHERE status IN('active','overdue')")->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    public function totalInterestExpected(): float
    {
        try { return (float)$this->db->query("SELECT COALESCE(SUM(interest_amount),0) FROM `loans`")->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    /**
     * Total interest actually collected across all loans.
     *
     * Sources: loan_repayments.interest_paid is populated for explicit interest
     * payments (Business Boost interest-only, weekly interest). Standard monthly
     * loan repayments currently record the full amount as principal_paid with
     * interest_paid = 0 — the system does not yet store a principal/interest
     * breakdown for standard installments. That gap will be addressed in a
     * future financial accuracy upgrade.
     */
    public function totalInterestCollected(): float
    {
        try {
            return (float)$this->db->query(
                "SELECT COALESCE(SUM(interest_paid),0) FROM `loan_repayments`"
            )->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    public function totalProcessingFees(): float
    {
        try { return (float)$this->db->query("SELECT COALESCE(SUM(processing_fee),0) FROM `loans`")->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    public function totalPortfolio(): float
    {
        try { return (float)$this->db->query("SELECT COALESCE(SUM(loan_amount),0) FROM `loans`")->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    public function dueThisMonth(): int
    {
        try { return (int)$this->db->query("SELECT COUNT(*) FROM `loans` WHERE status='active' AND MONTH(due_date)=MONTH(CURDATE()) AND YEAR(due_date)=YEAR(CURDATE())")->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    public function dueTodayCount(): int
    {
        try { return (int)$this->db->query("SELECT COUNT(*) FROM `loans` WHERE due_date=CURDATE() AND status='active'")->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    public function dueThisWeekCount(): int
    {
        try { return (int)$this->db->query("SELECT COUNT(*) FROM `loans` WHERE due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) AND status='active'")->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    /**
     * Member who has borrowed the most, all-time (sum of loan_amount across all their loans).
     */
    public function topBorrower(): array|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT m.id, m.first_name, m.last_name, m.member_number,
                        SUM(l.loan_amount) AS total_borrowed
                 FROM `loans` l
                 JOIN `members` m ON m.id = l.member_id
                 GROUP BY m.id, m.first_name, m.last_name, m.member_number
                 ORDER BY total_borrowed DESC
                 LIMIT 1"
            );
            $stmt->execute();
            return $stmt->fetch();
        } catch (PDOException $e) { return false; }
    }

    public function recentLoans(int $limit = 5): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT l.*, m.first_name, m.last_name, m.member_number
                 FROM `loans` l JOIN `members` m ON m.id = l.member_id
                 ORDER BY l.created_at DESC LIMIT ?"
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    // ================================================================
    // OVERDUE SYNC
    // ================================================================

    public function syncOverdueStatus(): void
    {
        try {
            $this->db->exec("UPDATE `loans` SET status='overdue' WHERE status='active' AND due_date < CURDATE()");
            $this->syncOverdueInstallments();
        } catch (PDOException $e) {}
    }

    // ================================================================
    // ALERTS
    // ================================================================

    public function getAlerts(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT l.*, m.first_name, m.last_name, m.member_number,
                        DATEDIFF(l.due_date, CURDATE()) AS days_remaining
                 FROM `loans` l JOIN `members` m ON m.id = l.member_id
                 WHERE l.status = 'active' AND l.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                 ORDER BY l.due_date ASC"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function getOverdueLoans(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT l.*, m.first_name, m.last_name, m.member_number,
                        DATEDIFF(CURDATE(), l.due_date) AS days_overdue
                 FROM `loans` l JOIN `members` m ON m.id = l.member_id
                 WHERE l.status='active' AND l.due_date < CURDATE()
                 ORDER BY l.due_date ASC"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    // ================================================================
    // SINGLE RECORD
    // ================================================================

    public function findWithDetails(int $id): array|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT l.*, m.first_name, m.last_name, m.member_number, m.phone AS member_phone,
                        m.gender, m.national_id,
                        u.full_name AS recorded_by_name,
                        lt.name AS loan_type_name,
                        je.entry_number
                 FROM `loans` l
                 JOIN `members` m ON m.id = l.member_id
                 LEFT JOIN `users` u ON u.id = l.recorded_by
                 LEFT JOIN `loan_types` lt ON lt.id = l.loan_type_id
                 LEFT JOIN `journal_entries` je ON je.id = l.journal_entry_id
                 WHERE l.id = ? LIMIT 1"
            );
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException $e) { return false; }
    }

    /**
     * Stage 14-B: the member portal's own loan-detail lookup. Identical to
     * findWithDetails() except ownership is enforced INSIDE the query
     * (AND l.member_id = ?) rather than fetched-then-checked -- a loan
     * belonging to a different member simply doesn't match any row, so
     * the portal controller gets the same "not found" result whether the
     * id is wrong or someone else's, with no way to distinguish the two.
     */
    public function findWithDetailsForMember(int $id, int $memberId): array|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT l.*, m.first_name, m.last_name, m.member_number, m.phone AS member_phone,
                        m.gender, m.national_id,
                        lt.name AS loan_type_name
                 FROM `loans` l
                 JOIN `members` m ON m.id = l.member_id
                 LEFT JOIN `loan_types` lt ON lt.id = l.loan_type_id
                 WHERE l.id = ? AND l.member_id = ? LIMIT 1"
            );
            $stmt->execute([$id, $memberId]);
            return $stmt->fetch();
        } catch (PDOException $e) { return false; }
    }

    // ================================================================
    // MEMBER LOAN HISTORY
    // ================================================================

    public function memberActiveLoan(int $memberId): array|false
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM `loans` WHERE member_id=? AND status IN('active','overdue') ORDER BY issue_date DESC LIMIT 1");
            $stmt->execute([$memberId]);
            return $stmt->fetch();
        } catch (PDOException $e) { return false; }
    }

    public function memberCompletedLoanCount(int $memberId): int
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `loans` WHERE member_id=? AND status='completed'");
            $stmt->execute([$memberId]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    public function memberLoanHistory(int $memberId, int $limit = 20): array
    {
        try {
            $stmt = $this->db->prepare("SELECT l.*, lt.name AS loan_type_name FROM `loans` l LEFT JOIN `loan_types` lt ON lt.id=l.loan_type_id WHERE l.member_id=? ORDER BY l.issue_date DESC LIMIT ?");
            $stmt->bindValue(1, $memberId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function memberLoanSummary(int $memberId): array
    {
        try {
            $total = (int)$this->db->prepare("SELECT COUNT(*) FROM loans WHERE member_id=?")->execute([$memberId]) ? 0 : 0;
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM loans WHERE member_id=?");
            $stmt->execute([$memberId]);
            $total = (int)$stmt->fetchColumn();

            $stmt = $this->db->prepare("SELECT COUNT(*) FROM loans WHERE member_id=? AND status IN('active','overdue')");
            $stmt->execute([$memberId]);
            $active = (int)$stmt->fetchColumn();

            $stmt = $this->db->prepare("SELECT COUNT(*) FROM loans WHERE member_id=? AND status='completed'");
            $stmt->execute([$memberId]);
            $completed = (int)$stmt->fetchColumn();

            $stmt = $this->db->prepare("SELECT COALESCE(SUM(outstanding),0) FROM loans WHERE member_id=? AND status IN('active','overdue')");
            $stmt->execute([$memberId]);
            $outstanding = (float)$stmt->fetchColumn();

            $stmt = $this->db->prepare("SELECT COALESCE(SUM(loan_amount),0) FROM loans WHERE member_id=?");
            $stmt->execute([$memberId]);
            $totalBorrowed = (float)$stmt->fetchColumn();

            $stmt = $this->db->prepare("SELECT COALESCE(SUM(interest_amount),0) FROM loans WHERE member_id=? AND status='completed'");
            $stmt->execute([$memberId]);
            $interestPaid = (float)$stmt->fetchColumn();

            return [
                'total_loans'    => $total,
                'active_loans'   => $active,
                'completed'      => $completed,
                'outstanding'    => $outstanding,
                'total_borrowed' => $totalBorrowed,
                'interest_paid'  => $interestPaid,
            ];
        } catch (PDOException $e) {
            return ['total_loans'=>0,'active_loans'=>0,'completed'=>0,'outstanding'=>0,'total_borrowed'=>0,'interest_paid'=>0];
        }
    }

    // ================================================================
    // LOAN PERFORMANCE SCORE
    // ================================================================

    public function getMemberPerformanceScore(int $memberId): array
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM loans WHERE member_id=? AND status='completed'");
            $stmt->execute([$memberId]);
            $completed = (int)$stmt->fetchColumn();

            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM loan_installments li
                 JOIN loans l ON l.id = li.loan_id
                 WHERE l.member_id=? AND li.status IN('missed','overdue')"
            );
            $stmt->execute([$memberId]);
            $missed = (int)$stmt->fetchColumn();

            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM loan_installments li
                 JOIN loans l ON l.id = li.loan_id
                 WHERE l.member_id=? AND li.status='paid'"
            );
            $stmt->execute([$memberId]);
            $onTime = (int)$stmt->fetchColumn();

            // Score: 5 stars max
            $totalPayments = $onTime + $missed;
            if ($totalPayments === 0) {
                $score = 0;
                $label = 'No History';
            } else {
                $ratio = $onTime / $totalPayments;
                if ($ratio >= 0.95 && $completed >= 1) { $score = 5; $label = 'Excellent'; }
                elseif ($ratio >= 0.80) { $score = 4; $label = 'Good'; }
                elseif ($ratio >= 0.60) { $score = 3; $label = 'Fair'; }
                elseif ($ratio >= 0.40) { $score = 2; $label = 'Poor'; }
                else { $score = 1; $label = 'Default Risk'; }
            }

            return ['score' => $score, 'label' => $label, 'on_time' => $onTime, 'missed' => $missed, 'completed_loans' => $completed];
        } catch (PDOException $e) {
            return ['score' => 0, 'label' => 'No History', 'on_time' => 0, 'missed' => 0, 'completed_loans' => 0];
        }
    }

    // ================================================================
    // PAGINATED SEARCH
    // ================================================================

    public function search(
        string $term = '', string $status = '', string $filter = '',
        int $typeId = 0, int $page = 1, int $perPage = 15
    ): array {
        $where = []; $params = [];

        if ($term !== '') {
            $like = '%' . $term . '%';
            $where[] = '(l.loan_number LIKE ? OR l.account_number LIKE ? OR m.member_number LIKE ? OR m.account_number LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ? OR l.loan_officer LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }
        if (in_array($status, ['draft','pending_approval','approved','rejected','pending','active','completed','overdue','defaulted'], true)) {
            $where[] = 'l.status = ?'; $params[] = $status;
        }
        if ($typeId > 0) { $where[] = 'l.loan_type_id = ?'; $params[] = $typeId; }

        switch ($filter) {
            case 'overdue': $where[] = "(l.status='overdue' OR (l.status='active' AND l.due_date < CURDATE()))"; break;
            case 'due-today': $where[] = "l.due_date = CURDATE() AND l.status='active'"; break;
            case 'due-week': $where[] = "l.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) AND l.status='active'"; break;
            case 'due-month': $where[] = "l.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND l.status='active'"; break;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset = ($page - 1) * $perPage;

        $from = "FROM `loans` l
                 JOIN `members` m ON m.id = l.member_id
                 LEFT JOIN `users` u ON u.id = l.recorded_by
                 LEFT JOIN `loan_types` lt ON lt.id = l.loan_type_id
                 {$whereSQL}";

        $countStmt = $this->db->prepare("SELECT COUNT(*) {$from}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $listStmt = $this->db->prepare(
            "SELECT l.*, m.first_name, m.last_name, m.member_number, m.account_number AS member_account_number,
                    u.full_name AS recorded_by_name, lt.name AS loan_type_name,
                    DATEDIFF(l.due_date, CURDATE()) AS days_remaining
             {$from} ORDER BY l.created_at DESC LIMIT ? OFFSET ?"
        );
        $i = 1;
        foreach ($params as $v) { $listStmt->bindValue($i++, $v); }
        $listStmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $listStmt->bindValue($i, $offset, PDO::PARAM_INT);
        $listStmt->execute();

        return [
            'rows'  => $listStmt->fetchAll(),
            'total' => $total,
            'pages' => $total > 0 ? (int)ceil($total / $perPage) : 1,
        ];
    }

    // ================================================================
    // APPROVAL QUEUE (dedicated "Awaiting Approval" view)
    // ================================================================

    public function countPendingApproval(): int
    {
        try { return (int)$this->db->query("SELECT COUNT(*) FROM `loans` WHERE status='pending_approval'")->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    /** Counts by approved_at, not current status -- a disbursed loan moves
     *  on to 'active' but approved_at is never cleared, so this still
     *  reflects everything approved this month regardless of what has
     *  happened to it since. */
    public function countApprovedThisMonth(): int
    {
        try {
            return (int)$this->db->query(
                "SELECT COUNT(*) FROM `loans` WHERE approved_at IS NOT NULL AND approved_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
            )->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    public function countRejectedThisMonth(): int
    {
        try {
            return (int)$this->db->query(
                "SELECT COUNT(*) FROM `loans` WHERE rejected_at IS NOT NULL AND rejected_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
            )->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    /** Dedicated query for the Awaiting Approval queue -- locked to
     *  status='pending_approval' (no status/due-date filters, unlike the
     *  general search() above, since neither applies pre-disbursement),
     *  ordered oldest-first so the longest-waiting application surfaces
     *  first, with a days_waiting aging column computed from submitted_at
     *  (falling back to created_at for the unexpected case of a
     *  pending_approval row with no submitted_at). */
    public function pendingApprovalQueue(string $term = '', int $page = 1, int $perPage = 15): array
    {
        $where  = ["l.status = 'pending_approval'"];
        $params = [];

        if ($term !== '') {
            $like = '%' . $term . '%';
            $where[] = '(l.loan_number LIKE ? OR m.member_number LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ? OR l.loan_officer LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);
        $offset   = ($page - 1) * $perPage;

        $from = "FROM `loans` l
                 JOIN `members` m ON m.id = l.member_id
                 LEFT JOIN `users` u ON u.id = l.recorded_by
                 LEFT JOIN `loan_types` lt ON lt.id = l.loan_type_id
                 {$whereSQL}";

        $countStmt = $this->db->prepare("SELECT COUNT(*) {$from}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $listStmt = $this->db->prepare(
            "SELECT l.*, m.first_name, m.last_name, m.member_number, m.account_number AS member_account_number,
                    u.full_name AS recorded_by_name, lt.name AS loan_type_name,
                    DATEDIFF(CURDATE(), COALESCE(l.submitted_at, l.created_at)) AS days_waiting
             {$from} ORDER BY COALESCE(l.submitted_at, l.created_at) ASC LIMIT ? OFFSET ?"
        );
        $i = 1;
        foreach ($params as $v) { $listStmt->bindValue($i++, $v); }
        $listStmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $listStmt->bindValue($i, $offset, PDO::PARAM_INT);
        $listStmt->execute();

        return [
            'rows'  => $listStmt->fetchAll(),
            'total' => $total,
            'pages' => $total > 0 ? (int)ceil($total / $perPage) : 1,
        ];
    }

    // ================================================================
    // ACTIVITY LOG
    // ================================================================

    public function log(int $userId, string $action, string $desc = ''): void
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO `activity_logs`(`user_id`,`action`,`description`,`ip_address`) VALUES (?,?,?,?)");
            $stmt->execute([$userId, $action, $desc, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (PDOException $e) {}
    }
}
