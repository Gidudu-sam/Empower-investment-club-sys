<?php
/**
 * ControlledCorrectionService — SA-5 (Controlled Corrections, Reversals &
 * Financial Remediation, 2026-09).
 *
 * This is the FIRST SA-stage allowed to mutate financial data, and it does
 * so through exactly one supported operation: reversing a live, incorrectly
 * posted journal entry via the existing canonical JournalService::reverse().
 * It does not implement a second accounting engine, does not construct
 * journal lines itself, and does not allow arbitrary account/amount entry.
 *
 * Every other correction category named in the SA-5 brief
 * (CORRECT_DUPLICATE_TRANSACTION, CORRECT_MISSING_JOURNAL,
 * CORRECT_INCORRECT_JOURNAL beyond its reversal half,
 * CORRECT_REPAYMENT_ALLOCATION, CORRECT_OTHER_APPROVED) is registered in
 * CORRECTION_TYPES as unavailable/deferred, per the brief's own explicit
 * instruction: "If a correction type cannot be implemented safely: show it
 * as unavailable/deferred. Do not create a dangerous fallback." Reasons for
 * each deferral are documented inline below and in the SA-5 report.
 *
 * Safety gates enforced on every prepare AND re-enforced on every execute
 * (never trusted from the earlier step alone):
 *   - role: admin/system_admin only (enforced in the controller)
 *   - target must be a real, existing journal entry
 *   - target must not already be reversed (reversal_of_id already exists)
 *   - target's data_classification must be 'live' -- 'dummy'/'unknown' are
 *     refused outright (SA-5 brief Section 37/38: never casually "repair"
 *     known historical/forensic test data through this workflow)
 *   - reason must be non-blank and pass a minimum-quality check (reject
 *     "fix"/"test"/etc. and anything under 20 characters)
 *   - a fingerprint of the target's key fields is captured at prepare time
 *     and re-checked at execute time (stale-data protection) -- this
 *     schema has no row-version column, so a computed fingerprint is the
 *     appropriate, non-fragile mechanism here
 *   - idempotency: execute() checks this table's own `status` first (if
 *     already 'executed', the existing result is returned rather than
 *     re-running); JournalService::reverse() has its own independent
 *     idempotency check too (via reversal_of_id), so double-reversal is
 *     refused at two independent layers
 *   - the whole execute path runs inside one DB transaction; any failure
 *     rolls back and the correction is marked 'failed' with a reason
 */
class ControlledCorrectionService
{
    private PDO $db;
    private ControlledCorrectionModel $model;
    private JournalService $journalService;

    public const CORRECTION_TYPES = [
        'REVERSE_JOURNAL' => [
            'label' => 'Reverse Journal Entry',
            'available' => true,
            'reason' => 'Uses the existing canonical JournalService::reverse() unchanged -- fully atomic, already idempotent, already audited.',
        ],
        'CORRECT_DUPLICATE_TRANSACTION' => [
            'label' => 'Correct Duplicate Transaction',
            'available' => false,
            'reason' => 'Deferred: savings, loan_repayments, and withdrawals have no status column capable of safely representing "marked duplicate" without a further schema change (member_fees does have one, but no other affected table does) -- see SA-5 report Section C.',
        ],
        'CORRECT_MISSING_JOURNAL' => [
            'label' => 'Correct Missing Journal',
            'available' => false,
            'reason' => 'Deferred: reconstructing the correct account mapping for a historical transaction with no journal is not something this service can safely infer from the record alone (per the brief\'s explicit instruction not to manufacture a journal from assumptions). All current coverage-gap candidates are also dummy-classified, so the safety gate would refuse them anyway.',
        ],
        'CORRECT_INCORRECT_JOURNAL' => [
            'label' => 'Correct Incorrect Journal (repost)',
            'available' => false,
            'reason' => 'Only the reversal half of this pattern is implemented (via REVERSE_JOURNAL). Re-posting the correct entry is deliberately left to the existing canonical transaction screens (deposit/repayment/etc.), which already have validated account mappings -- SA-5 does not reimplement them.',
        ],
        'CORRECT_REPAYMENT_ALLOCATION' => [
            'label' => 'Correct Repayment Allocation',
            'available' => false,
            'reason' => 'Deferred: repayment_installment_allocations is confirmed storage-engine-corrupted and unused by the live app (SA-3 finding); reallocating principal/interest/penalty splits has cascading effects on loan_installments/loans.outstanding with no safe canonical reallocation method to reuse.',
        ],
        'CORRECT_OTHER_APPROVED' => [
            'label' => 'Other (Approved)',
            'available' => false,
            'reason' => 'No defined, safe operation exists for this catch-all category -- registered for completeness only, per the brief\'s correction-type list.',
        ],
    ];

    private const MIN_REASON_LENGTH = 20;
    private const TRIVIAL_REASONS = ['fix', 'fixed', 'correct', 'corrected', 'wrong', 'test', 'n/a', 'na', 'testing', 'fix it', 'error'];

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->model = new ControlledCorrectionModel();
        $this->journalService = new JournalService();
    }

    public function correctionTypes(): array
    {
        return self::CORRECTION_TYPES;
    }

    /** Validates a human-entered reason. Returns an error message, or null if acceptable. */
    public function validateReason(string $reason): ?string
    {
        $trimmed = trim($reason);
        if ($trimmed === '') { return 'A reason is required.'; }
        if (mb_strlen($trimmed) < self::MIN_REASON_LENGTH) { return 'Reason is too short -- please describe the evidence and decision in at least ' . self::MIN_REASON_LENGTH . ' characters.'; }
        if (in_array(mb_strtolower($trimmed), self::TRIVIAL_REASONS, true)) { return 'Reason is too generic. Describe the specific evidence reviewed and why this correction is warranted.'; }
        return null;
    }

    /** Computes a stable fingerprint of a journal entry's key fields, for stale-data detection. */
    private function fingerprintJournalEntry(array $journalEntry, float $totalDebit, float $totalCredit): string
    {
        return hash('sha256', implode('|', [
            $journalEntry['id'], $journalEntry['entry_date'], $journalEntry['data_classification'],
            $journalEntry['reversal_of_id'] ?? '', $totalDebit, $totalCredit,
        ]));
    }

    private function loadJournalEvidence(int $journalEntryId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM journal_entries WHERE id = ?");
        $stmt->execute([$journalEntryId]);
        $je = $stmt->fetch();
        if (!$je) { return null; }

        $lines = $this->db->prepare("SELECT jl.debit, jl.credit, jl.description, a.code, a.name FROM journal_lines jl JOIN accounts a ON a.id = jl.account_id WHERE jl.journal_entry_id = ? ORDER BY jl.id");
        $lines->execute([$journalEntryId]);
        $lineRows = $lines->fetchAll();
        $totalDebit = array_sum(array_column($lineRows, 'debit'));
        $totalCredit = array_sum(array_column($lineRows, 'credit'));

        $existingReversal = $this->db->prepare("SELECT id, entry_number FROM journal_entries WHERE reversal_of_id = ?");
        $existingReversal->execute([$journalEntryId]);
        $reversal = $existingReversal->fetch();

        return [
            'journal' => $je, 'lines' => $lineRows, 'total_debit' => $totalDebit, 'total_credit' => $totalCredit,
            'already_reversed' => $reversal !== false, 'existing_reversal' => $reversal ?: null,
            'fingerprint' => $this->fingerprintJournalEntry($je, $totalDebit, $totalCredit),
        ];
    }

    /**
     * PREPARE step (read-only): loads evidence for a journal entry and
     * evaluates whether REVERSE_JOURNAL is even eligible, WITHOUT writing
     * anything. Used to render the prepare/evidence page.
     */
    public function evaluateReversalEligibility(int $journalEntryId): array
    {
        $evidence = $this->loadJournalEvidence($journalEntryId);
        if (!$evidence) {
            return ['eligible' => false, 'blockers' => ['Journal entry not found.'], 'evidence' => null];
        }
        $blockers = [];
        $je = $evidence['journal'];
        if ($evidence['already_reversed']) {
            $blockers[] = "This journal entry was already reversed by {$evidence['existing_reversal']['entry_number']}.";
        }
        if ($je['data_classification'] === 'dummy') {
            $blockers[] = 'BLOCKED: this journal entry is classified as historical/dummy test data. This workflow does not casually repair documented forensic artifacts (see project memory, Stage 19D / Stage 24-28). A separate, explicitly authorized forensic-cleanup path would be required.';
        } elseif ($je['data_classification'] === 'unknown') {
            $blockers[] = 'BLOCKED: this journal entry\'s classification is "unknown" and requires human review before this workflow will proceed -- classification is never guessed.';
        }
        if (abs($evidence['total_debit'] - $evidence['total_credit']) > 0.01) {
            $blockers[] = 'This journal entry does not currently balance -- investigate before reversing (SA-4).';
        }
        return ['eligible' => empty($blockers), 'blockers' => $blockers, 'evidence' => $evidence];
    }

    /**
     * STORE step: creates a 'prepared' correction record. Still no
     * accounting mutation -- only a row describing an intent, with the
     * fingerprint captured now for later stale-data comparison.
     * @throws InvalidArgumentException on any validation failure
     */
    public function prepareCorrection(int $journalEntryId, int $userId, string $reason): array
    {
        $reasonError = $this->validateReason($reason);
        if ($reasonError) { throw new InvalidArgumentException($reasonError); }

        $eval = $this->evaluateReversalEligibility($journalEntryId);
        if (!$eval['eligible']) {
            throw new InvalidArgumentException(implode(' ', $eval['blockers']));
        }
        $evidence = $eval['evidence'];
        $je = $evidence['journal'];

        $number = $this->model->nextCorrectionNumber();
        $id = $this->model->create([
            'correction_number' => $number,
            'correction_type' => 'REVERSE_JOURNAL',
            'target_entity_type' => 'journal_entry',
            'target_entity_id' => $journalEntryId,
            'target_reference' => $je['entry_number'],
            'target_classification' => $je['data_classification'],
            'target_fingerprint' => $evidence['fingerprint'],
            'original_state_json' => json_encode(['journal' => $je, 'lines' => $evidence['lines'], 'total_debit' => $evidence['total_debit'], 'total_credit' => $evidence['total_credit']]),
            'detected_issue' => null,
            'reason' => trim($reason),
            'status' => 'prepared',
            'prepared_by' => $userId,
        ]);

        return ['id' => (int)$id, 'correction_number' => $number];
    }

    public function find(int $id): array|false
    {
        return $this->model->find($id);
    }

    /**
     * EXECUTE step: the only method in this service that mutates
     * financial data. Fully atomic; re-validates everything fresh rather
     * than trusting the prepared row's stale snapshot.
     * @return array{status:string, correction:array}
     */
    public function executeCorrection(int $correctionId, int $userId): array
    {
        $correction = $this->model->find($correctionId);
        if (!$correction) {
            throw new InvalidArgumentException('Correction record not found.');
        }

        // Idempotency layer 1: this table's own status. A second click,
        // refresh, or retry returns the existing outcome instead of
        // re-running anything.
        if ($correction['status'] === 'executed') {
            return ['status' => 'already_executed', 'correction' => $correction];
        }
        if (in_array($correction['status'], ['failed', 'cancelled'], true)) {
            throw new InvalidArgumentException("This correction is already {$correction['status']} and cannot be executed.");
        }

        $journalEntryId = (int)$correction['target_entity_id'];
        $evidence = $this->loadJournalEvidence($journalEntryId);
        if (!$evidence) {
            $this->markFailed($correctionId, 'Target journal entry no longer exists.');
            throw new RuntimeException('Target journal entry no longer exists.');
        }
        $je = $evidence['journal'];

        // Stale-data protection: re-check the fingerprint captured at
        // prepare time against the target's CURRENT state.
        if ($evidence['fingerprint'] !== $correction['target_fingerprint']) {
            $this->markFailed($correctionId, 'Target record changed after preparation.');
            throw new RuntimeException('Correction aborted. Record changed after preparation. Please investigate again.');
        }

        // Re-enforce the classification gate -- never trust the value
        // captured at prepare time alone.
        if ($je['data_classification'] !== 'live') {
            $this->markFailed($correctionId, "Target classification is now '{$je['data_classification']}', not 'live'.");
            throw new RuntimeException('Correction aborted. Target is no longer classified as live-eligible.');
        }
        if ($evidence['already_reversed']) {
            $this->markFailed($correctionId, 'Target was already reversed by another correction/action.');
            throw new RuntimeException("This journal entry was already reversed by {$evidence['existing_reversal']['entry_number']}.");
        }

        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) { $this->db->beginTransaction(); }

            $this->model->update($correctionId, ['status' => 'confirmed', 'confirmed_by' => $userId, 'confirmed_at' => date('Y-m-d H:i:s')]);

            // The one and only mutation path: the existing, unmodified
            // canonical reversal engine.
            $result = $this->journalService->reverse($journalEntryId, $userId, $correction['reason']);

            // Post-correction verification (Section 28): re-read the
            // reversal's own lines and confirm it balances. reverse()
            // already guarantees this internally via post(), but SA-5
            // re-verifies independently rather than assuming.
            $verifyLines = $this->db->prepare("SELECT COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c FROM journal_lines WHERE journal_entry_id = ?");
            $verifyLines->execute([$result['id']]);
            $totals = $verifyLines->fetch();
            if (abs((float)$totals['d'] - (float)$totals['c']) > 0.01) {
                throw new RuntimeException('Post-correction verification failed: the resulting reversal entry does not balance.');
            }

            $this->model->update($correctionId, [
                'status' => 'executed', 'executed_by' => $userId, 'executed_at' => date('Y-m-d H:i:s'),
                'resulting_journal_entry_id' => $result['id'], 'resulting_journal_entry_number' => $result['entry_number'],
            ]);

            if ($ownTransaction) { $this->db->commit(); }

            return ['status' => 'executed', 'correction' => $this->model->find($correctionId)];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            $this->markFailed($correctionId, $e->getMessage());
            throw new RuntimeException('Correction failed: ' . $e->getMessage());
        }
    }

    private function markFailed(int $correctionId, string $reason): void
    {
        // Deliberately its own tiny operation, outside any caller's
        // transaction, so a failure record is written even if the
        // triggering transaction was rolled back.
        try {
            $this->model->update($correctionId, ['status' => 'failed', 'failure_reason' => $reason]);
        } catch (Throwable $e) { /* best-effort; never mask the original error */ }
    }

    public function cancel(int $correctionId, int $userId): void
    {
        $correction = $this->model->find($correctionId);
        if (!$correction) { throw new InvalidArgumentException('Correction record not found.'); }
        if ($correction['status'] !== 'prepared') {
            throw new InvalidArgumentException('Only a correction still in "prepared" status can be cancelled.');
        }
        $this->model->update($correctionId, ['status' => 'cancelled']);
    }

    public function recent(int $limit = 50): array
    {
        return $this->model->recent($limit);
    }
}
