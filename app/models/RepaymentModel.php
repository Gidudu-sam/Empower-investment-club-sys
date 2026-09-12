<?php
/**
 * Stage — Loan Repayment Interest Recognition & Submission Integrity:
 * thrown when an INSERT into loan_repayments violates
 * uk_repayments_submission_token -- i.e. this exact submission attempt
 * (not merely "a similar payment") has already been recorded. Callers
 * (RepaymentController) catch this specifically and redirect to the
 * already-recorded repayment instead of showing a raw DB error or,
 * worse, retrying and creating a second financial event.
 */
class DuplicateRepaymentSubmissionException extends RuntimeException {}

/**
 * RepaymentModel — Multi-Product Loan Repayments
 */
class RepaymentModel extends Model
{
    protected string $table      = 'loan_repayments';
    protected string $primaryKey = 'id';

    /** payment_method -> debit-side cash/bank GL account id (same mapping as LoanModel/SavingsModel/ExpenseModel) */
    private const PAYMENT_ACCOUNTS = [
        'Cash'              => 7,  // 1110 Cash at Hand
        'MTN Mobile Money'  => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Airtel Money'      => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Bank Transfer'     => 10, // 1140 Bank Accounts
        'Cheque'            => 10, // 1140 Bank Accounts
        'Other'             => 7,  // fallback: Cash at Hand
    ];

    private const LOANS_RECEIVABLE_ACCOUNT = 14; // 1180 Loans to Members
    private const INTEREST_INCOME_ACCOUNT  = 77; // 4035 Loan Interest Income
    private const PENALTIES_ACCOUNT        = 34; // 4060 Penalties

    // ----------------------------------------------------------------
    // Cash Reference generation (CHL-000001) -- a NEW, separate identifier
    // from repayment_number above, populated only for payment_method =
    // Cash. Same row-locked journal_number_sequences + FOR UPDATE pattern
    // used everywhere else in this codebase -- never MAX()+1, never
    // reused after a reversal/deletion.
    // ----------------------------------------------------------------
    private function nextCashReference(string $prefix): string
    {
        $stmt = $this->db->prepare("SELECT last_number FROM `journal_number_sequences` WHERE prefix = ? FOR UPDATE");
        $stmt->execute([$prefix]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No journal_number_sequences row for prefix '{$prefix}'.");
        }
        $next = (int)$row['last_number'] + 1;
        $this->db->prepare("UPDATE `journal_number_sequences` SET last_number = ? WHERE prefix = ?")
            ->execute([$next, $prefix]);
        return sprintf('%s-%06d', $prefix, $next);
    }

    // ================================================================
    // REPAYMENT NUMBER
    // ================================================================

    public function generateRepaymentNumber(): string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT MAX(CAST(SUBSTRING(repayment_number, 5) AS UNSIGNED)) AS max_seq FROM `loan_repayments`"
            );
            $stmt->execute();
            $next = (int)($stmt->fetch()['max_seq'] ?? 0) + 1;
            return 'PAY-' . str_pad($next, 6, '0', STR_PAD_LEFT);
        } catch (PDOException $e) { return 'PAY-000001'; }
    }

    // ================================================================
    // SUBMISSION-TOKEN IDEMPOTENCY (Stage — Repayment Interest
    // Recognition & Submission Integrity)
    //
    // A fresh, server-generated token is embedded as a hidden field every
    // time the repayment form is rendered (RepaymentController::add()).
    // It is submitted back with the POST and inserted alongside the
    // repayment row it protects. uk_repayments_submission_token (a real
    // database-level UNIQUE constraint, not merely an application check)
    // is the actual guarantee: two near-simultaneous requests for the
    // SAME rendered form both race to INSERT the same token -- exactly
    // one wins; the other's INSERT is rejected by MySQL itself before it
    // can ever commit a second financial event, closing the race-
    // condition gap a pure "SELECT first, then INSERT" check cannot
    // close. A genuinely separate, legitimate repeat payment (the member
    // pays the same amount again later) naturally gets its own fresh
    // token, because the operator loads the Record Repayment form again
    // for it -- this distinguishes "the same submission, twice" from
    // "two real payments" without inspecting amount/date/method at all.
    // ================================================================

    /** The repayment already recorded for this exact submission token, if any. */
    public function findBySubmissionToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM `loan_repayments` WHERE submission_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Shared INSERT used by every record*() method below -- unchanged in
     * shape from what each method already built inline (dynamic column
     * list from array_keys($data)), factored out only so the duplicate-
     * submission translation lives in exactly one place. Never changes
     * what data is inserted; only how a unique-constraint violation on
     * submission_token is reported to the caller.
     */
    private function insertRepaymentRow(array $data): int
    {
        $columns      = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        try {
            $stmt = $this->db->prepare("INSERT INTO `loan_repayments` ({$columns}) VALUES ({$placeholders})");
            $stmt->execute(array_values($data));
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uk_repayments_submission_token')) {
                throw new DuplicateRepaymentSubmissionException(
                    'This repayment was already submitted and recorded. Refusing to record it a second time.', 0, $e
                );
            }
            throw $e;
        }
        return (int)$this->db->lastInsertId();
    }

    // ================================================================
    // ACCOUNTING — journal posting for a repayment (Stage 9)
    // ================================================================

    /**
     * Post a just-inserted repayment row as a journal entry, inside the
     * caller's already-open transaction: Dr <cash/bank>, Cr Loans to
     * Members (principal_paid), Cr Loan Interest Income (interest_paid +
     * savings_paid -- both are stored as interest_paid today; savings_paid
     * is always 0 per the existing repayment-type logic), Cr Penalties
     * (penalty_paid), whichever of the three credit lines are > 0.
     *
     * Idempotent via JournalService::post()'s own source-reference
     * uniqueness check (source_module='loan_repayments',
     * source_reference_type='repayment', source_reference_id=$repaymentId)
     * -- mirrors LoanModel::postDisbursement()/SavingsModel::postDeposit().
     * Uses JournalService::post() as the only journal-writing path.
     */
    private function postRepaymentJournal(int $repaymentId, array $data, int $userId): array
    {
        $paymentMethod  = $data['payment_method'] ?? 'Cash';
        $debitAccountId = self::PAYMENT_ACCOUNTS[$paymentMethod] ?? 7;
        $debitAccount   = (new AccountModel())->findActive($debitAccountId);
        if (!$debitAccount) {
            throw new InvalidArgumentException("The cash/bank account for payment method \"{$paymentMethod}\" does not exist or is inactive.");
        }

        $principal = (float)($data['principal_paid'] ?? 0);
        $interest  = (float)($data['interest_paid'] ?? 0) + (float)($data['savings_paid'] ?? 0);
        $penalty   = (float)($data['penalty_paid'] ?? 0);
        $total     = (float)$data['amount_paid'];

        $lines = [
            ['account_id' => $debitAccount['id'], 'debit' => $total, 'credit' => 0, 'description' => $paymentMethod],
        ];
        if ($principal > 0) {
            $receivable = (new AccountModel())->findActive(self::LOANS_RECEIVABLE_ACCOUNT);
            if (!$receivable) {
                throw new InvalidArgumentException('The Loans to Members receivable account does not exist or is inactive.');
            }
            $lines[] = ['account_id' => $receivable['id'], 'debit' => 0, 'credit' => $principal, 'description' => 'Principal'];
        }
        if ($interest > 0) {
            $incomeAccount = (new AccountModel())->findActive(self::INTEREST_INCOME_ACCOUNT);
            if (!$incomeAccount) {
                throw new InvalidArgumentException('The Loan Interest Income account does not exist or is inactive.');
            }
            $lines[] = ['account_id' => $incomeAccount['id'], 'debit' => 0, 'credit' => $interest, 'description' => 'Interest'];
        }
        if ($penalty > 0) {
            $penaltyAccount = (new AccountModel())->findActive(self::PENALTIES_ACCOUNT);
            if (!$penaltyAccount) {
                throw new InvalidArgumentException('The Penalties account does not exist or is inactive.');
            }
            $lines[] = ['account_id' => $penaltyAccount['id'], 'debit' => 0, 'credit' => $penalty, 'description' => 'Penalty'];
        }

        // If none of principal/interest/penalty account for the full amount
        // (shouldn't happen given the existing principal+interest+savings+penalty
        // = amount_paid invariant, but guard rather than post an unbalanced entry),
        // route the remainder to interest income rather than silently dropping it.
        $accountedFor = $principal + $interest + $penalty;
        if (round($accountedFor, 2) !== round($total, 2)) {
            $incomeAccount = (new AccountModel())->findActive(self::INTEREST_INCOME_ACCOUNT);
            $lines[] = ['account_id' => $incomeAccount['id'], 'debit' => 0, 'credit' => round($total - $accountedFor, 2), 'description' => 'Unclassified'];
        }

        $periodStmt = $this->db->prepare("
            SELECT ap.id AS period_id, ap.financial_year_id
            FROM `accounting_periods` ap
            LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
            WHERE ap.status = 'open'
              AND ap.start_date <= ? AND ap.end_date >= ?
              AND (fy.status = 'active' OR fy.status IS NULL)
            LIMIT 1
        ");
        $periodStmt->execute([$data['payment_date'], $data['payment_date']]);
        $period = $periodStmt->fetch();

        $service = new JournalService();
        $result = $service->post([
            'entry_date'             => $data['payment_date'],
            'description'            => "Loan repayment {$data['repayment_number']}",
            'source_module'          => 'loan_repayments',
            'source_reference_type'  => 'repayment',
            'source_reference_id'    => $repaymentId,
            'financial_year_id'      => $period['financial_year_id'] ?? null,
            'accounting_period_id'   => $period['period_id'] ?? null,
            'created_by'             => $userId,
            'lines'                  => $lines,
        ]);

        $this->db->prepare("UPDATE `loan_repayments` SET journal_entry_id = ? WHERE id = ?")->execute([$result['id'], $repaymentId]);

        return ['journal_entry_id' => $result['id'], 'entry_number' => $result['entry_number'], 'created' => $result['created']];
    }

    // ================================================================
    // PENALTY COLLECTION (Stage 17 Part C)
    //
    // "Outstanding penalty" = for each distinct installment_id that has at
    // least one 'accruing' loan_penalties row, only the LATEST
    // (calculated_date DESC, id DESC) row is authoritative -- it already
    // holds the correct cumulative penalty_amount as of its own
    // calculation. Older same-installment 'accruing' rows from earlier
    // calculatePenalties() runs are superseded snapshots, excluded here to
    // avoid double-counting. See results/stage17_penalty_evidence/
    // 04_repayment_allocation_analysis.txt for the full decision record.
    // ================================================================

    private function latestAccruingPenaltyIds(int $loanId): array
    {
        $stmt = $this->db->prepare("
            SELECT lp.id
            FROM `loan_penalties` lp
            WHERE lp.loan_id = ? AND lp.status = 'accruing'
              AND lp.id = (
                  SELECT lp2.id FROM `loan_penalties` lp2
                  WHERE lp2.loan_id = lp.loan_id
                    AND (lp2.installment_id <=> lp.installment_id)
                    AND lp2.status = 'accruing'
                  ORDER BY lp2.calculated_date DESC, lp2.id DESC
                  LIMIT 1
              )
        ");
        $stmt->execute([$loanId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The outstanding (latest-per-installment) accruing penalty rows for a
     * loan, oldest overdue installment first. Pass $forUpdate=true to lock
     * these specific rows inside an already-open transaction.
     */
    public function getOutstandingPenaltyRows(int $loanId, bool $forUpdate = false): array
    {
        $ids = $this->latestAccruingPenaltyIds($loanId);
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $lock = $forUpdate ? ' FOR UPDATE' : '';

        try {
            $stmt = $this->db->prepare("
                SELECT lp.*, li.due_date AS installment_due_date
                FROM `loan_penalties` lp
                LEFT JOIN `loan_installments` li ON li.id = lp.installment_id
                WHERE lp.id IN ({$placeholders})
                ORDER BY COALESCE(li.due_date, lp.calculated_date) ASC, lp.id ASC{$lock}
            ");
            $stmt->execute($ids);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            // loan_installments has a documented history of storage-engine
            // issues (Stage 7E/13) -- fall back to ordering by calculated_date
            // alone rather than letting the whole allocation fail.
            $stmt = $this->db->prepare("
                SELECT lp.* FROM `loan_penalties` lp
                WHERE lp.id IN ({$placeholders})
                ORDER BY lp.calculated_date ASC, lp.id ASC{$lock}
            ");
            $stmt->execute($ids);
            return $stmt->fetchAll();
        }
    }

    /** Total outstanding (collectible) penalty for a loan right now. */
    public function getOutstandingPenalty(int $loanId): float
    {
        $total = 0.0;
        foreach ($this->getOutstandingPenaltyRows($loanId) as $row) {
            $total += max(0, round((float)$row['penalty_amount'] - (float)$row['amount_paid'], 2));
        }
        return round($total, 2);
    }

    /**
     * Allocate a validated penalty payment across the loan's outstanding
     * accruing penalty rows (oldest overdue installment first), flipping a
     * row to 'paid' only once it is FULLY settled. Must be called inside an
     * already-open transaction that already holds a lock on the parent
     * `loans` row (recordRepayment() does, via its own SELECT ... FOR
     * UPDATE) -- that lock already serializes concurrent penalty
     * allocation attempts against the same loan; the FOR UPDATE here is
     * additional defense in depth on the specific penalty rows themselves.
     *
     * @throws InvalidArgumentException if $penaltyPaid exceeds the
     *         authoritative (freshly re-read, locked) outstanding total.
     */
    private function allocatePenaltyPayment(int $loanId, float $penaltyPaid, string $paymentDate): void
    {
        $rows = $this->getOutstandingPenaltyRows($loanId, true);

        $totalOutstanding = 0.0;
        foreach ($rows as $row) {
            $totalOutstanding += max(0, round((float)$row['penalty_amount'] - (float)$row['amount_paid'], 2));
        }
        $totalOutstanding = round($totalOutstanding, 2);

        if (round($penaltyPaid, 2) > $totalOutstanding + 0.01) {
            throw new InvalidArgumentException(
                'Penalty payment of Shs ' . number_format($penaltyPaid, 2) .
                ' exceeds the outstanding penalty of Shs ' . number_format($totalOutstanding, 2) . ' for this loan.'
            );
        }

        $remaining = $penaltyPaid;
        foreach ($rows as $row) {
            if ($remaining <= 0.001) break;

            $rowRemaining = round((float)$row['penalty_amount'] - (float)$row['amount_paid'], 2);
            if ($rowRemaining <= 0) continue;

            $apply = min($remaining, $rowRemaining);
            $newAmountPaid = round((float)$row['amount_paid'] + $apply, 2);
            $isFullyPaid = $newAmountPaid >= round((float)$row['penalty_amount'] - 0.001, 2);

            if ($isFullyPaid) {
                $this->db->prepare(
                    "UPDATE `loan_penalties` SET amount_paid=?, status='paid', paid_date=? WHERE id=?"
                )->execute([$newAmountPaid, $paymentDate, $row['id']]);

                // Any OTHER 'accruing' row sharing this same (loan_id,
                // installment_id) is a superseded daily snapshot of the
                // exact same underlying debt (see the "multiple accruing
                // rows per installment" finding in 03_penalty_architecture.
                // txt) -- once the current/latest snapshot is fully settled,
                // a stale duplicate must not be left behind to incorrectly
                // "resurface" as newly outstanding the next time
                // getOutstandingPenaltyRows() picks the latest-remaining-
                // accruing row for this installment. Close them out
                // together, same paid_date, rather than leaving orphaned
                // accruing debt for a penalty that has already been paid.
                $this->db->prepare(
                    "UPDATE `loan_penalties`
                     SET amount_paid = penalty_amount, status = 'paid', paid_date = ?
                     WHERE loan_id = ? AND status = 'accruing' AND id != ?
                       AND (installment_id <=> ?)"
                )->execute([$paymentDate, $loanId, $row['id'], $row['installment_id']]);
            } else {
                $this->db->prepare(
                    "UPDATE `loan_penalties` SET amount_paid=? WHERE id=?"
                )->execute([$newAmountPaid, $row['id']]);
            }

            $remaining = round($remaining - $apply, 2);
        }

        // Recompute loans.penalty_accrued using the correct latest-per-
        // installment definition -- calculatePenalties() itself (unmodified)
        // still uses its own naive SUM whenever IT runs; that pre-existing
        // discrepancy is documented, not fixed here (out of scope).
        $newOutstanding = $this->getOutstandingPenalty($loanId);
        $this->db->prepare("UPDATE `loans` SET penalty_accrued=? WHERE id=?")->execute([$newOutstanding, $loanId]);
    }

    // ================================================================
    // RECORD REPAYMENT (Normal & Asset Financing)
    // ================================================================

    public function recordRepayment(array $data): int|false
    {
        try {
            $this->db->beginTransaction();

            $loanStmt = $this->db->prepare("SELECT id, outstanding, total_payable, status, loan_type_id, loan_amount, interest_amount FROM `loans` WHERE id = ? FOR UPDATE");
            $loanStmt->execute([$data['loan_id']]);
            $loan = $loanStmt->fetch();
            if (!$loan) { $this->db->rollBack(); return false; }

            $paid        = (float)$data['amount_paid'];
            $penaltyPaid = round((float)($data['penalty_paid'] ?? 0), 2);

            if ($penaltyPaid < 0) {
                throw new InvalidArgumentException('Penalty payment cannot be negative.');
            }
            if ($penaltyPaid > $paid) {
                throw new InvalidArgumentException('Penalty payment cannot exceed the total amount paid.');
            }
            if ($penaltyPaid > 0) {
                // Authoritative validation + allocation, re-checked fresh
                // under lock -- never trusts a client-submitted figure.
                $this->allocatePenaltyPayment((int)$loan['id'], $penaltyPaid, $data['payment_date'] ?? date('Y-m-d'));
            }

            // The penalty portion is tracked exclusively via loan_penalties/
            // penalty_accrued, exactly mirroring the accounting treatment
            // (Loans Receivable 1180 vs Penalties 4060 are different GL
            // accounts) -- loans.outstanding/amount_paid and the installment
            // schedule must only ever see the NON-penalty portion of the
            // payment, or penalty cash would incorrectly pay down principal.
            $nonPenaltyPaid = round($paid - $penaltyPaid, 2);

            $balanceBefore = (float)$loan['outstanding'];

            // Stage — Loan Schedule & Due-Date Integrity Remediation
            // (Remediation F): the model layer must not silently absorb
            // an excess payment by clamping outstanding to 0 -- reject it
            // outright, before any row is inserted or any balance is
            // touched, exactly like the controller's own pre-existing
            // validate() check already does for the real HTTP flow. This
            // protects any OTHER caller of this model directly (a future
            // API, an import script, a console command) that does not
            // go through RepaymentController::validate().
            if ($nonPenaltyPaid > $balanceBefore + 0.01) {
                throw new InvalidArgumentException(
                    'Payment of Shs ' . number_format($nonPenaltyPaid, 2) .
                    ' exceeds the outstanding balance of Shs ' . number_format($balanceBefore, 2) . '.'
                );
            }

            $balanceAfter = max(0, round($balanceBefore - $nonPenaltyPaid, 2));

            $data['balance_before'] = $balanceBefore;
            $data['balance_after']  = $balanceAfter;
            $data['loan_type_id']   = $data['loan_type_id'] ?? $loan['loan_type_id'];
            $data['payment_type']   = $data['payment_type'] ?? 'installment';

            // Stage — Loan Repayment Interest Recognition: split the
            // non-penalty portion of THIS payment between principal and
            // interest using the loan's own stored flat-interest facts
            // (loan_amount, interest_amount) -- never a new interest
            // formula. A live SUM() over this loan's prior repayments
            // (not a cached running total -- LoanProvisioningService
            // already treats loan_repayments.principal_paid/interest_paid
            // as the sole authoritative source, and distrusts cached
            // totals) tells us how much of the loan's fixed interest has
            // already been recognised, so a partial-payment sequence
            // converges to exact totals at payoff instead of drifting.
            if (!array_key_exists('principal_paid', $data) && !array_key_exists('interest_paid', $data)) {
                $paidTotals = $this->db->prepare(
                    "SELECT COALESCE(SUM(principal_paid),0) p, COALESCE(SUM(interest_paid),0) i FROM `loan_repayments` WHERE loan_id=?"
                );
                $paidTotals->execute([$loan['id']]);
                $paidSoFar = $paidTotals->fetch();

                $interestRemaining = max(0, round((float)$loan['interest_amount'] - (float)$paidSoFar['i'], 2));
                $totalPayableOriginal = (float)$loan['loan_amount'] + (float)$loan['interest_amount'];

                $interestPaid = 0.0;
                if ($interestRemaining > 0.005 && $totalPayableOriginal > 0) {
                    $interestRatio = (float)$loan['interest_amount'] / $totalPayableOriginal;
                    $interestPaid = min(round($nonPenaltyPaid * $interestRatio, 2), $interestRemaining, $nonPenaltyPaid);
                }
                $principalPaid = max(0, round($nonPenaltyPaid - $interestPaid, 2));
            } else {
                $interestPaid  = (float)($data['interest_paid'] ?? 0);
                $principalPaid = (float)($data['principal_paid'] ?? $nonPenaltyPaid);
            }
            $data['principal_paid'] = $principalPaid;
            $data['interest_paid']  = $interestPaid;
            $data['savings_paid']   = $data['savings_paid'] ?? 0;
            $data['penalty_paid']   = $penaltyPaid;

            $data['cash_reference_number'] = ($data['payment_method'] ?? null) === 'Cash'
                ? $this->nextCashReference('CHL')
                : null;
            $data['submission_token'] = $data['submission_token'] ?? null;

            $newId = $this->insertRepaymentRow($data);

            // Update installment schedule (non-penalty portion only) --
            // moved BEFORE the loans status computation below (Stage —
            // Loan Schedule & Due-Date Integrity Remediation, Remediation
            // G) so scheduleFullyReconciled() sees THIS payment's effect
            // on the installment rows, not their pre-payment state.
            $this->updateInstallmentOnPayment((int)$loan['id'], $nonPenaltyPaid, $data['payment_date'] ?? null);

            // Update loan. Completion now also requires the installment
            // schedule (where one exists) to be fully reconciled, not
            // just outstanding<=0 -- closes the gap where loans.outstanding
            // could reach zero while a scheduled installment technically
            // remained open. A loan with NO installment schedule at all
            // (a legitimate schedule-less product) is unaffected:
            // scheduleFullyReconciled() returns true when there is
            // nothing to reconcile, preserving its existing lifecycle.
            $newStatus = ($balanceAfter <= 0 && $this->scheduleFullyReconciled((int)$loan['id'])) ? 'completed' : $loan['status'];
            $this->db->prepare(
                "UPDATE `loans` SET outstanding=?, amount_paid=amount_paid+?, status=?, last_payment_date=CURDATE(),
                 next_payment_date=DATE_ADD(CURDATE(), INTERVAL 1 MONTH) WHERE id=?"
            )->execute([$balanceAfter, $nonPenaltyPaid, $newStatus, $loan['id']]);

            $data['id'] = $newId;
            $this->postRepaymentJournal($newId, $data, (int)($data['received_by'] ?? 0));

            $this->db->commit();
            return $newId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ================================================================
    // RECORD BUSINESS LOAN INTEREST PAYMENT
    // ================================================================

    public function recordInterestPayment(array $data): int|false
    {
        try {
            $this->db->beginTransaction();

            $loanStmt = $this->db->prepare("SELECT id, outstanding, loan_type_id, interest_paid_total FROM `loans` WHERE id=? FOR UPDATE");
            $loanStmt->execute([$data['loan_id']]);
            $loan = $loanStmt->fetch();
            if (!$loan) { $this->db->rollBack(); return false; }

            $paid = (float)$data['amount_paid'];

            $data['balance_before'] = (float)$loan['outstanding'];
            $data['balance_after']  = (float)$loan['outstanding']; // Interest doesn't reduce principal
            $data['loan_type_id']   = $loan['loan_type_id'];
            $data['payment_type']   = 'interest';
            $data['principal_paid'] = 0;
            $data['interest_paid']  = $paid;
            $data['savings_paid']   = 0;
            $data['penalty_paid']   = 0;

            $data['cash_reference_number'] = ($data['payment_method'] ?? null) === 'Cash'
                ? $this->nextCashReference('CHL')
                : null;
            $data['submission_token'] = $data['submission_token'] ?? null;

            $newId = $this->insertRepaymentRow($data);

            // Update loan interest_paid_total (does NOT reduce outstanding)
            $this->db->prepare(
                "UPDATE `loans` SET interest_paid_total=interest_paid_total+?, last_payment_date=CURDATE() WHERE id=?"
            )->execute([$paid, $loan['id']]);

            // Update installment schedule (mark interest installment as paid)
            $this->updateInstallmentOnPayment((int)$loan['id'], $paid, $data['payment_date'] ?? null);

            // Update business_loan_interest_payments if matching pending entry
            $this->db->prepare(
                "UPDATE `business_loan_interest_payments`
                 SET interest_paid=interest_paid+?, status=IF(interest_paid+?>=interest_due,'paid','partial'), paid_date=CURDATE()
                 WHERE loan_id=? AND status IN('pending','partial','overdue')
                 ORDER BY due_date ASC LIMIT 1"
            )->execute([$paid, $paid, $loan['id']]);

            $this->postRepaymentJournal($newId, $data, (int)($data['received_by'] ?? 0));

            $this->db->commit();
            return $newId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ================================================================
    // RECORD BUSINESS LOAN WEEKLY SAVINGS
    // ================================================================

    public function recordWeeklySavings(array $data): int|false
    {
        try {
            $this->db->beginTransaction();

            $loanStmt = $this->db->prepare("SELECT id, outstanding, loan_type_id FROM `loans` WHERE id=?");
            $loanStmt->execute([$data['loan_id']]);
            $loan = $loanStmt->fetch();
            if (!$loan) { $this->db->rollBack(); return false; }

            $paid = (float)$data['amount_paid'];

            $data['balance_before'] = (float)$loan['outstanding'];
            $data['balance_after']  = (float)$loan['outstanding']; // Weekly interest doesn't reduce principal
            $data['loan_type_id']   = $loan['loan_type_id'];
            $data['payment_type']   = 'weekly_savings';
            $data['principal_paid'] = 0;
            $data['interest_paid']  = $paid;
            $data['savings_paid']   = 0;
            $data['penalty_paid']   = 0;
            $data['week_covered']   = $data['week_covered'] ?? date('W/Y');

            $data['cash_reference_number'] = ($data['payment_method'] ?? null) === 'Cash'
                ? $this->nextCashReference('CHL')
                : null;
            $data['submission_token'] = $data['submission_token'] ?? null;

            $newId = $this->insertRepaymentRow($data);

            // Update loan interest_paid_total
            $this->db->prepare(
                "UPDATE `loans` SET interest_paid_total=interest_paid_total+?, last_payment_date=CURDATE() WHERE id=?"
            )->execute([$paid, $loan['id']]);

            // Update installment schedule — mark next pending interest installment
            $this->updateInstallmentOnPayment((int)$loan['id'], $paid, $data['payment_date'] ?? null);

            $this->postRepaymentJournal($newId, $data, (int)($data['received_by'] ?? 0));

            $this->db->commit();
            return $newId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ================================================================
    // RECORD BUSINESS LOAN PRINCIPAL PAYMENT
    // ================================================================

    public function recordPrincipalPayment(array $data): int|false
    {
        try {
            $this->db->beginTransaction();

            $loanStmt = $this->db->prepare("SELECT id, outstanding, loan_type_id, status FROM `loans` WHERE id=? FOR UPDATE");
            $loanStmt->execute([$data['loan_id']]);
            $loan = $loanStmt->fetch();
            if (!$loan) { $this->db->rollBack(); return false; }

            $paid = (float)$data['amount_paid'];
            $outstandingBefore = (float)$loan['outstanding'];

            // Stage — Loan Schedule & Due-Date Integrity Remediation
            // (Remediation F, applied consistently to this variant too --
            // same defect class, no change to allocation policy).
            if ($paid > $outstandingBefore + 0.01) {
                throw new InvalidArgumentException(
                    'Payment of Shs ' . number_format($paid, 2) .
                    ' exceeds the outstanding balance of Shs ' . number_format($outstandingBefore, 2) . '.'
                );
            }

            $balanceAfter = max(0, $outstandingBefore - $paid);

            $data['balance_before'] = $outstandingBefore;
            $data['balance_after']  = $balanceAfter;
            $data['loan_type_id']   = $loan['loan_type_id'];
            $data['payment_type']   = 'principal';
            $data['principal_paid'] = $paid;
            $data['interest_paid']  = 0;
            $data['savings_paid']   = 0;
            $data['penalty_paid']   = 0;

            $data['cash_reference_number'] = ($data['payment_method'] ?? null) === 'Cash'
                ? $this->nextCashReference('CHL')
                : null;
            $data['submission_token'] = $data['submission_token'] ?? null;

            $newId = $this->insertRepaymentRow($data);

            $newStatus = $balanceAfter <= 0 ? 'completed' : $loan['status'];
            $this->db->prepare(
                "UPDATE `loans` SET outstanding=?, amount_paid=amount_paid+?, status=?, last_payment_date=CURDATE() WHERE id=?"
            )->execute([$balanceAfter, $paid, $newStatus, $loan['id']]);

            $this->postRepaymentJournal($newId, $data, (int)($data['received_by'] ?? 0));

            $this->db->commit();
            return $newId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ================================================================
    // DELETE (Stage 9 — reverses the loan balance, the installment
    // schedule, and now also the posted journal entry, atomically)
    // ================================================================

    public function delete(int $id, int $userId = 0): bool
    {
        $repayment = $this->find($id);
        if (!$repayment) {
            return false;
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $loanId      = (int)$repayment['loan_id'];
            $amountPaid  = (float)$repayment['amount_paid'];
            $paymentType = $repayment['payment_type'] ?? 'installment';

            if (in_array($paymentType, ['installment', 'principal', 'settlement'], true)) {
                $this->db->prepare("UPDATE `loans` SET outstanding=outstanding+?, amount_paid=amount_paid-? WHERE id=?")->execute([$amountPaid, $amountPaid, $loanId]);
            } elseif ($paymentType === 'interest') {
                $this->db->prepare("UPDATE `loans` SET interest_paid_total=interest_paid_total-? WHERE id=?")->execute([$amountPaid, $loanId]);
            }

            // loan_installments may currently be unreadable at the storage-
            // engine level (Stage 7E) -- guard independently so that its
            // failure never blocks the loan-balance reversal, the journal
            // reversal, or the repayment delete itself, which are the parts
            // that matter for accounting/orphan-prevention correctness.
            try {
                $this->db->prepare(
                    "UPDATE `loan_installments` SET amount_paid=GREATEST(0, amount_paid-?), status='pending', paid_date=NULL
                     WHERE loan_id=? AND status='paid' ORDER BY installment_no DESC LIMIT 1"
                )->execute([$amountPaid, $loanId]);
            } catch (PDOException $e) {
                error_log('RepaymentModel::delete() could not reverse loan_installments for loan ' . $loanId . ': ' . $e->getMessage());
            }

            if (!empty($repayment['journal_entry_id'])) {
                (new JournalService())->reverse((int)$repayment['journal_entry_id'], $userId, 'Repayment deleted');
            }

            $stmt = $this->db->prepare("DELETE FROM `loan_repayments` WHERE id=?");
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
    // UPDATE INSTALLMENT ON PAYMENT
    // ================================================================

    /**
     * Stage — Loan Schedule & Due-Date Integrity Remediation
     * (Remediation G): true when this loan has no installment schedule
     * at all (a legitimate schedule-less product -- its existing
     * completion lifecycle is preserved unchanged) OR every installment
     * row has reached status='paid'. Used only by recordRepayment()'s
     * completion check -- the standard/installment path, the only one
     * where loan_installments is actually kept in sync with payments.
     * The business-loan variants (recordPrincipalPayment(),
     * recordInterestPayment(), recordWeeklySavings()) are deliberately
     * NOT changed to use this: they do not maintain loan_installments the
     * same way, and retrofitting that would be exactly the business-loan-
     * variant redesign this stage is scoped to avoid.
     */
    private function scheduleFullyReconciled(int $loanId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) total, COALESCE(SUM(status='paid'),0) paid FROM `loan_installments` WHERE loan_id=?"
        );
        $stmt->execute([$loanId]);
        $r = $stmt->fetch();
        return (int)$r['total'] === 0 || (int)$r['total'] === (int)$r['paid'];
    }

    /**
     * When a payment is recorded, update the next pending/overdue installment.
     */
    private function updateInstallmentOnPayment(int $loanId, float $amountPaid, ?string $paymentDate = null): void
    {
        try {
            $pDate = $paymentDate ?: date('Y-m-d');

            // Get pending/overdue installments in order
            $stmt = $this->db->prepare(
                "SELECT * FROM `loan_installments`
                 WHERE `loan_id`=? AND `status` IN('pending','partial','overdue')
                 ORDER BY `installment_no` ASC"
            );
            $stmt->execute([$loanId]);
            $installments = $stmt->fetchAll();

            if (empty($installments)) return;

            $remaining = $amountPaid;

            // For weekly schedules: match payment to the correct week by date
            // Find the installment whose due_date is closest to the payment date
            $isWeekly = ($installments[0]['period_type'] ?? '') === 'weekly';

            if ($isWeekly) {
                // Find the best matching installment(s) by payment date
                $matchedInstallments = $this->findInstallmentsByPaymentDate($installments, $pDate);

                foreach ($matchedInstallments as $inst) {
                    if ($remaining <= 0) break;

                    $owed = (float)$inst['amount_due'] - (float)$inst['amount_paid'];
                    if ($owed <= 0) continue;

                    $pay = min($remaining, $owed);
                    $newPaid = (float)$inst['amount_paid'] + $pay;
                    $newStatus = ($newPaid >= (float)$inst['amount_due']) ? 'paid' : 'partial';

                    $this->db->prepare(
                        "UPDATE `loan_installments`
                         SET `amount_paid`=?, `status`=?, `paid_date`=?,
                             `remaining`=GREATEST(0, `amount_due` - ?)
                         WHERE `id`=?"
                    )->execute([$newPaid, $newStatus, $pDate, $newPaid, $inst['id']]);

                    $remaining -= $pay;
                }
            } else {
                // Monthly schedule: sequential matching (existing behavior)
                foreach ($installments as $inst) {
                    if ($remaining <= 0) break;

                    $owed = (float)$inst['amount_due'] - (float)$inst['amount_paid'];
                    if ($owed <= 0) continue;

                    $pay = min($remaining, $owed);
                    $newPaid = (float)$inst['amount_paid'] + $pay;
                    $newStatus = ($newPaid >= (float)$inst['amount_due']) ? 'paid' : 'partial';
                    $paidDate = ($newStatus === 'paid') ? $pDate : null;

                    $this->db->prepare(
                        "UPDATE `loan_installments`
                         SET `amount_paid`=?, `status`=?, `paid_date`=?,
                             `remaining`=GREATEST(0, `amount_due` - ?)
                         WHERE `id`=?"
                    )->execute([$newPaid, $newStatus, $paidDate, $newPaid, $inst['id']]);

                    $remaining -= $pay;
                }
            }
        } catch (PDOException $e) {
            // loan_installments is currently unreadable at the storage-engine
            // level (Stage 7E) -- the repayment itself and its journal still
            // post correctly; only the schedule display is affected. Log
            // rather than let it fail invisibly.
            error_log('updateInstallmentOnPayment() failed for loan ' . $loanId . ': ' . $e->getMessage());
        }
    }

    /**
     * Find the installment(s) that best match a payment date for weekly schedules.
     * Matches the installment whose due_date the payment falls within (±3 days),
     * or the installment for the current/nearest week.
     */
    private function findInstallmentsByPaymentDate(array $installments, string $paymentDate): array
    {
        $pTime = strtotime($paymentDate);
        $matched = [];

        foreach ($installments as $inst) {
            $dueTime = strtotime($inst['due_date']);
            // Payment falls within ±3 days of the due date (same week)
            $daysDiff = abs(($pTime - $dueTime) / 86400);
            if ($daysDiff <= 3) {
                $matched[] = $inst;
                break; // One payment = one week
            }
        }

        // If no close match found, find the installment whose due date is
        // on or just before the payment date (the current week)
        if (empty($matched)) {
            $bestMatch = null;
            foreach ($installments as $inst) {
                $dueTime = strtotime($inst['due_date']);
                if ($dueTime <= $pTime) {
                    $bestMatch = $inst; // Keep the latest due date that's <= payment date
                } else {
                    // Due date is after payment — check if it's the closest upcoming
                    if (!$bestMatch) {
                        $bestMatch = $inst;
                    }
                    break;
                }
            }
            if ($bestMatch) $matched[] = $bestMatch;
        }

        // Fallback: if still nothing, just use the first pending one
        if (empty($matched) && !empty($installments)) {
            $matched[] = $installments[0];
        }

        return $matched;
    }

    // ================================================================
    // QUERIES
    // ================================================================

    public function findWithDetails(int $id): array|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT r.*, l.loan_number, l.loan_amount, l.total_payable, l.outstanding AS loan_outstanding, l.loan_type_id,
                        m.first_name, m.last_name, m.member_number, m.phone AS member_phone,
                        u.full_name AS cashier_name, lt.name AS loan_type_name
                 FROM `loan_repayments` r
                 JOIN `loans` l ON l.id = r.loan_id
                 JOIN `members` m ON m.id = r.member_id
                 LEFT JOIN `users` u ON u.id = r.received_by
                 LEFT JOIN `loan_types` lt ON lt.id = r.loan_type_id
                 WHERE r.id = ? LIMIT 1"
            );
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException $e) { return false; }
    }

    public function forLoan(int $loanId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT r.*, u.full_name AS cashier_name
                 FROM `loan_repayments` r LEFT JOIN `users` u ON u.id = r.received_by
                 WHERE r.loan_id = ? ORDER BY r.payment_date ASC, r.id ASC"
            );
            $stmt->execute([$loanId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function totalPaidForLoan(int $loanId): float
    {
        try {
            $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM `loan_repayments` WHERE loan_id=?");
            $stmt->execute([$loanId]);
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    // ================================================================
    // DASHBOARD STATS
    // ================================================================

    public function todayCollections(): float
    {
        try {
            return (float)$this->db->query("SELECT COALESCE(SUM(amount_paid),0) FROM loan_repayments WHERE payment_date=CURDATE()")->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    public function monthCollections(): float
    {
        try {
            return (float)$this->db->query("SELECT COALESCE(SUM(amount_paid),0) FROM loan_repayments WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())")->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    public function recentRepayments(int $limit = 5): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT r.*, l.loan_number, m.first_name, m.last_name, m.member_number
                 FROM `loan_repayments` r JOIN `loans` l ON l.id=r.loan_id JOIN `members` m ON m.id=r.member_id
                 ORDER BY r.created_at DESC LIMIT ?"
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    // ================================================================
    // SEARCH
    // ================================================================

    public function search(string $term='', string $method='', string $dateFrom='', string $dateTo='', int $loanId=0, int $memberId=0, int $page=1, int $perPage=15): array
    {
        $where = []; $params = [];

        if ($term !== '') {
            $like = '%'.$term.'%';
            $where[] = '(r.repayment_number LIKE ? OR l.loan_number LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_number LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($method !== '') { $where[] = 'r.payment_method=?'; $params[] = $method; }
        if ($dateFrom !== '') { $where[] = 'r.payment_date>=?'; $params[] = $dateFrom; }
        if ($dateTo !== '') { $where[] = 'r.payment_date<=?'; $params[] = $dateTo; }
        if ($loanId > 0) { $where[] = 'r.loan_id=?'; $params[] = $loanId; }
        if ($memberId > 0) { $where[] = 'r.member_id=?'; $params[] = $memberId; }

        $whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';
        $offset = ($page-1)*$perPage;

        $from = "FROM `loan_repayments` r JOIN `loans` l ON l.id=r.loan_id JOIN `members` m ON m.id=r.member_id LEFT JOIN `users` u ON u.id=r.received_by {$whereSQL}";

        $countStmt = $this->db->prepare("SELECT COUNT(*) {$from}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sumStmt = $this->db->prepare("SELECT COALESCE(SUM(r.amount_paid),0) {$from}");
        $sumStmt->execute($params);
        $totalPaid = (float)$sumStmt->fetchColumn();

        $listStmt = $this->db->prepare(
            "SELECT r.*, l.loan_number, m.first_name, m.last_name, m.member_number, u.full_name AS cashier_name
             {$from} ORDER BY r.payment_date DESC, r.id DESC LIMIT ? OFFSET ?"
        );
        $i = 1;
        foreach ($params as $v) { $listStmt->bindValue($i++, $v); }
        $listStmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $listStmt->bindValue($i, $offset, PDO::PARAM_INT);
        $listStmt->execute();

        return ['rows'=>$listStmt->fetchAll(),'total'=>$total,'pages'=>max(1,(int)ceil($total/$perPage)),'totalPaid'=>$totalPaid];
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
