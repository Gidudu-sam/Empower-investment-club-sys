<?php
/**
 * LoanProvisioningService — implements the management-approved policy
 * PROV-001 (results/stage_20B_completed_management_decision_sheet.md).
 *
 * Exposure: unpaid principal only, derived from loans.loan_amount minus
 * dated loan_repayments.principal_paid (never loans.outstanding, never
 * loans.total_principal_paid, never loan_installments.remaining — all
 * three confirmed unreliable/wrong-basis in Stage 19/20/21).
 *
 * Aging: oldest qualifying loan_installments row with due_date <=
 * as_of_date and not fully satisfied (extends the same query shape as
 * the already-proven LoanProductModel::calculatePenalties()).
 *
 * Reproducibility (Stage 21 gate, mandatory): calculateRun() writes a
 * frozen snapshot into loan_provisioning_run_details. Nothing here ever
 * re-derives a finalized run's figures from live data — RepaymentModel::
 * delete() hard-deletes loan_repayments rows with no dated reversal
 * trail, so only the snapshot is trustworthy after the fact.
 */
class LoanProvisioningService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Active policy + its bands, ordered by sort_order. */
    public function getActivePolicy(): array
    {
        $policy = $this->db->query(
            "SELECT * FROM loan_provisioning_policies WHERE status='active' ORDER BY id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$policy) {
            throw new RuntimeException('No active loan_provisioning_policies row found.');
        }
        $bands = $this->db->prepare(
            "SELECT * FROM loan_provisioning_policy_bands WHERE policy_id=? ORDER BY sort_order"
        );
        $bands->execute([$policy['id']]);
        $policy['bands'] = $bands->fetchAll(PDO::FETCH_ASSOC);
        return $policy;
    }

    /**
     * Unpaid Principal Exposure = MAX(loan_amount - SUM(principal_paid
     * WHERE payment_date <= as_of_date), 0). PROV-001 Decisions #1-#5.
     */
    public function calculateExposure(int $loanId, string $asOfDate): array
    {
        $loan = $this->db->prepare("SELECT id, loan_amount FROM loans WHERE id=?");
        $loan->execute([$loanId]);
        $loan = $loan->fetch(PDO::FETCH_ASSOC);
        if (!$loan) {
            throw new InvalidArgumentException("Loan id {$loanId} not found.");
        }
        $original = (float)$loan['loan_amount'];

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(principal_paid), 0) FROM loan_repayments
             WHERE loan_id = ? AND payment_date <= ?"
        );
        $stmt->execute([$loanId, $asOfDate]);
        $paid = (float)$stmt->fetchColumn();

        $exposure = max($original - $paid, 0.0);

        return [
            'original_principal'        => round($original, 2),
            'qualifying_principal_paid' => round($paid, 2),
            'unpaid_principal_exposure' => round($exposure, 2),
        ];
    }

    /**
     * Oldest qualifying (due_date <= as_of_date, not fully satisfied)
     * installment for a loan. PROV-001 Decisions #6, #7, #13, #16, #17,
     * #19, #20. "Fully satisfied" is amount_paid >= amount_due — this is
     * the same comparison RepaymentModel's own write paths use to set
     * status='paid' at write time (Stage 21 gate §7/§11); status is not
     * itself trusted as the sole signal here, both conditions are
     * checked directly against the installment row.
     */
    public function calculateAging(int $loanId, string $asOfDate): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, due_date, amount_due, amount_paid, status
             FROM loan_installments
             WHERE loan_id = ?
               AND due_date <= ?
               AND status <> 'paid'
               AND amount_paid < amount_due
             ORDER BY due_date ASC
             LIMIT 1"
        );
        $stmt->execute([$loanId, $asOfDate]);
        $inst = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inst) {
            return [
                'oldest_qualifying_installment_id' => null,
                'oldest_qualifying_due_date'        => null,
                'days_past_due'                     => 0,
            ];
        }

        $due = new DateTimeImmutable($inst['due_date']);
        $asOf = new DateTimeImmutable($asOfDate);
        $daysPastDue = max(0, (int)$asOf->diff($due)->format('%r%a') * -1);

        return [
            'oldest_qualifying_installment_id' => (int)$inst['id'],
            'oldest_qualifying_due_date'        => $inst['due_date'],
            'days_past_due'                     => $daysPastDue,
        ];
    }

    /** First band whose [min_days, max_days] contains daysPastDue. max_days=NULL means unbounded. */
    public function resolveBucket(int $daysPastDue, array $bands): array
    {
        foreach ($bands as $band) {
            $min = (int)$band['min_days'];
            $max = $band['max_days'] === null ? null : (int)$band['max_days'];
            if ($daysPastDue >= $min && ($max === null || $daysPastDue <= $max)) {
                return $band;
            }
        }
        throw new RuntimeException("No provisioning band matches days_past_due={$daysPastDue}.");
    }

    /**
     * Previous provision for a loan = the required_provision recorded in
     * that loan's most recent row across all FINALIZED runs (frozen
     * snapshots only — never derived from a live recalculation).
     */
    public function getPreviousProvision(int $loanId): float
    {
        $stmt = $this->db->prepare(
            "SELECT d.required_provision
             FROM loan_provisioning_run_details d
             JOIN loan_provisioning_runs r ON r.id = d.run_id
             WHERE d.loan_id = ? AND r.status = 'finalized'
             ORDER BY r.finalized_at DESC
             LIMIT 1"
        );
        $stmt->execute([$loanId]);
        $v = $stmt->fetchColumn();
        return $v === false ? 0.0 : round((float)$v, 2);
    }

    /**
     * Eligible loans as of a date: excludes loans issued after as_of_date
     * (PROV-001 Decision #15) and loans that were never actually
     * disbursed (draft/pending_approval/rejected — these carry no real
     * principal exposure; not a new policy rule, a technical necessity
     * since such loans have no meaningful loan_amount disbursed).
     */
    private function eligibleLoanIds(string $asOfDate): array
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM loans
             WHERE issue_date IS NOT NULL AND issue_date <= ?
               AND status NOT IN ('draft','pending_approval','rejected')"
        );
        $stmt->execute([$asOfDate]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Create a draft run and populate its frozen detail rows. Does NOT
     * post any journal entry — that only happens at finalizeRun().
     */
    public function calculateRun(int $accountingPeriodId, string $asOfDate, int $calculatedBy): int
    {
        $policy = $this->getActivePolicy();
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $runNumber = $this->nextRunNumber();
            $this->db->prepare(
                "INSERT INTO loan_provisioning_runs
                    (run_number, policy_id, accounting_period_id, as_of_date, status, calculated_by, calculated_at)
                 VALUES (?,?,?,?,'calculated',?,NOW())"
            )->execute([$runNumber, $policy['id'], $accountingPeriodId, $asOfDate, $calculatedBy]);
            $runId = (int)$this->db->lastInsertId();

            $detailStmt = $this->db->prepare(
                "INSERT INTO loan_provisioning_run_details
                    (run_id, loan_id, member_id, original_principal, qualifying_principal_paid,
                     unpaid_principal_exposure, oldest_qualifying_installment_id, oldest_qualifying_due_date,
                     days_past_due, bucket_name, bucket_min_days, bucket_max_days, applied_rate,
                     required_provision, previous_provision, delta, exclusion_reason)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );

            $totalExposure = 0.0;
            $totalRequired = 0.0;
            $totalPrevious = 0.0;
            $totalDelta = 0.0;
            $loanCount = 0;

            foreach ($this->eligibleLoanIds($asOfDate) as $loanId) {
                $loanRow = $this->db->prepare("SELECT member_id, loan_amount FROM loans WHERE id=?");
                $loanRow->execute([$loanId]);
                $loanRow = $loanRow->fetch(PDO::FETCH_ASSOC);

                $exclusionReason = null;
                if ($loanRow === false || $loanRow['loan_amount'] === null || (float)$loanRow['loan_amount'] < 0) {
                    // Data-quality guard (Stage 21 §23): never silently "fix" or
                    // silently classify as Current — flag and exclude from totals.
                    $exclusionReason = 'Missing or negative loan_amount — excluded from calculation, not silently treated as Current.';
                }

                $exposure = $exclusionReason ? ['original_principal' => 0, 'qualifying_principal_paid' => 0, 'unpaid_principal_exposure' => 0]
                                              : $this->calculateExposure($loanId, $asOfDate);
                $aging = $exclusionReason ? ['oldest_qualifying_installment_id' => null, 'oldest_qualifying_due_date' => null, 'days_past_due' => 0]
                                           : $this->calculateAging($loanId, $asOfDate);
                $band = $this->resolveBucket($aging['days_past_due'], $policy['bands']);

                $required = $exclusionReason ? 0.0 : round($exposure['unpaid_principal_exposure'] * ((float)$band['rate'] / 100), 2);
                $previous = $this->getPreviousProvision($loanId);
                $delta = round($required - $previous, 2);

                $detailStmt->execute([
                    $runId, $loanId, $loanRow['member_id'] ?? 0,
                    $exposure['original_principal'], $exposure['qualifying_principal_paid'], $exposure['unpaid_principal_exposure'],
                    $aging['oldest_qualifying_installment_id'], $aging['oldest_qualifying_due_date'], $aging['days_past_due'],
                    $band['bucket_name'], $band['min_days'], $band['max_days'], $band['rate'],
                    $required, $previous, $delta, $exclusionReason,
                ]);

                if (!$exclusionReason) {
                    $totalExposure += $exposure['unpaid_principal_exposure'];
                    $totalRequired += $required;
                    $totalPrevious += $previous;
                    $totalDelta += $delta;
                    $loanCount++;
                }
            }

            $this->db->prepare(
                "UPDATE loan_provisioning_runs
                 SET total_exposure=?, total_required_provision=?, total_previous_provision=?, total_delta=?, loan_count=?
                 WHERE id=?"
            )->execute([round($totalExposure, 2), round($totalRequired, 2), round($totalPrevious, 2), round($totalDelta, 2), $loanCount, $runId]);

            if ($ownTransaction) {
                $this->db->commit();
            }
            return $runId;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function reviewRun(int $runId, int $reviewedBy): void
    {
        $run = $this->getRun($runId);
        if ($run['status'] !== 'calculated') {
            throw new RuntimeException("Run {$runId} is not in 'calculated' status; cannot review.");
        }
        $this->db->prepare("UPDATE loan_provisioning_runs SET status='reviewed', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$reviewedBy, $runId]);
    }

    /**
     * Finalize: enforce Calculator != Finalizer (PROV-001 Decision #28),
     * post exactly one consolidated journal entry through the existing
     * JournalService::post() (no parallel accounting mechanism — PROV-001
     * Decision #18/§19), and lock the run as immutable.
     */
    public function finalizeRun(int $runId, int $finalizedBy): array
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            // Stage 21-C Finding 2 (hardened): lock the run row for the
            // duration of the status check + finalize, so two concurrent
            // finalize requests cannot both pass the status check before
            // either commits. Read is intentionally repeated here (not
            // reusing a pre-transaction getRun() call) so the locked row
            // reflects the true current state under the lock.
            $stmt = $this->db->prepare("SELECT * FROM loan_provisioning_runs WHERE id=? FOR UPDATE");
            $stmt->execute([$runId]);
            $run = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$run) {
                throw new InvalidArgumentException("Provisioning run {$runId} not found.");
            }
            if (!in_array($run['status'], ['calculated', 'reviewed'], true)) {
                throw new RuntimeException("Run {$runId} status '{$run['status']}' cannot be finalized.");
            }
            if ((int)$run['calculated_by'] === $finalizedBy) {
                throw new RuntimeException('Maker-checker violation: the calculator of a run may not also finalize it (PROV-001 Decision #28).');
            }

            $delta = round((float)$run['total_delta'], 2);
            $journalEntryId = null;

            if (abs($delta) >= 0.01) {
                $accounts = $this->resolveAccounts();
                $lines = $delta > 0
                    ? [ // increase: Dr 5300 / Cr 1185
                        ['account_id' => $accounts['5300'], 'debit' => abs($delta), 'credit' => 0],
                        ['account_id' => $accounts['1185'], 'debit' => 0, 'credit' => abs($delta)],
                    ]
                    : [ // decrease: Dr 1185 / Cr 5300
                        ['account_id' => $accounts['1185'], 'debit' => abs($delta), 'credit' => 0],
                        ['account_id' => $accounts['5300'], 'debit' => 0, 'credit' => abs($delta)],
                    ];

                $period = $this->db->prepare("SELECT financial_year_id FROM accounting_periods WHERE id=?");
                $period->execute([$run['accounting_period_id']]);
                $financialYearId = $period->fetchColumn();

                $result = (new JournalService())->post([
                    'lines'                  => $lines,
                    'entry_date'             => $run['as_of_date'],
                    'financial_year_id'      => $financialYearId ?: null,
                    'accounting_period_id'   => $run['accounting_period_id'],
                    'source_module'          => 'loan_provisioning',
                    'source_reference_type'  => 'loan_provisioning_run',
                    'source_reference_id'    => $runId,
                    'description'            => "Loan-loss provisioning run {$run['run_number']} (as of {$run['as_of_date']})",
                    'created_by'             => $finalizedBy,
                ]);
                $journalEntryId = $result['id'];
            }
            // Zero delta: no journal entry posted (PROV-001 §16/§23).

            $this->db->prepare(
                "UPDATE loan_provisioning_runs
                 SET status='finalized', finalized_by=?, finalized_at=NOW(), journal_entry_id=?
                 WHERE id=?"
            )->execute([$finalizedBy, $journalEntryId, $runId]);

            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['run_id' => $runId, 'journal_entry_id' => $journalEntryId, 'delta' => $delta];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Forward-only correction (PROV-001 Decision #24/#25): never edits a
     * finalized run. Creates a brand-new run referencing the one it
     * corrects via corrects_run_id, following the same
     * prepare-then-execute discipline ControlledCorrectionService already
     * uses for journal-level corrections.
     */
    public function createCorrectionRun(int $correctsRunId, int $accountingPeriodId, string $asOfDate, int $calculatedBy): int
    {
        $newRunId = $this->calculateRun($accountingPeriodId, $asOfDate, $calculatedBy);
        $this->db->prepare("UPDATE loan_provisioning_runs SET corrects_run_id=? WHERE id=?")->execute([$correctsRunId, $newRunId]);
        return $newRunId;
    }

    public function getRun(int $runId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM loan_provisioning_runs WHERE id=?");
        $stmt->execute([$runId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            throw new InvalidArgumentException("Provisioning run {$runId} not found.");
        }
        return $run;
    }

    public function getRunDetails(int $runId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM loan_provisioning_run_details WHERE run_id=? ORDER BY loan_id");
        $stmt->execute([$runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function resolveAccounts(): array
    {
        $stmt = $this->db->prepare("SELECT code, id FROM accounts WHERE code IN ('1185','5300')");
        $stmt->execute();
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[$row['code']] = (int)$row['id'];
        }
        if (!isset($map['1185']) || !isset($map['5300'])) {
            throw new RuntimeException('Accounts 1185/5300 not found — cannot post provisioning journal.');
        }
        return $map;
    }

    private function nextRunNumber(): string
    {
        $stmt = $this->db->prepare("SELECT last_number FROM journal_number_sequences WHERE prefix='PROVRUN' FOR UPDATE");
        $stmt->execute();
        $last = $stmt->fetchColumn();
        if ($last === false) {
            throw new RuntimeException("No journal_number_sequences row for prefix 'PROVRUN'.");
        }
        $next = (int)$last + 1;
        $this->db->prepare("UPDATE journal_number_sequences SET last_number=? WHERE prefix='PROVRUN'")->execute([$next]);
        return 'PROVRUN' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
    }
}
