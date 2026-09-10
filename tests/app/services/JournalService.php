<?php
/**
 * JournalService — the single, authoritative way to post a double-entry
 * journal entry. No other code may write directly to journal_entries or
 * journal_lines.
 *
 * post() guarantees, in order: every line is single-sided (debit XOR credit,
 * never both, never neither); at least 2 lines; every account exists and is
 * active; the target accounting period (if any) is open; debits == credits
 * exactly; duplicate (source_module, source_reference_type, source_reference_id)
 * short-circuits to the existing entry instead of creating a second one;
 * the journal number is generated under a row lock to avoid races; an audit
 * row is written; all of it inside one transaction.
 */
class JournalService
{
    private PDO $db;
    private AccountModel $accountModel;
    private JournalEntryModel $entryModel;
    private JournalLineModel $lineModel;

    public function __construct()
    {
        $this->db           = Database::getInstance()->getConnection();
        $this->accountModel = new AccountModel();
        $this->entryModel   = new JournalEntryModel();
        $this->lineModel    = new JournalLineModel();
    }

    /**
     * @param array $request {
     *   entry_date: string (Y-m-d),
     *   description?: string,
     *   source_module?: string,
     *   source_reference_type?: string,
     *   source_reference_id?: int,
     *   financial_year_id?: int,
     *   accounting_period_id?: int,
     *   created_by: int,
     *   lines: array<array{account_id:int, debit:float, credit:float, description?:string}>
     * }
     * @return array{id:int, entry_number:string, created:bool}
     */
    public function post(array $request): array
    {
        $lines = $request['lines'] ?? [];
        $this->validateLines($lines);

        $sourceModule    = $request['source_module'] ?? null;
        $sourceRefType   = $request['source_reference_type'] ?? null;
        $sourceRefId     = $request['source_reference_id'] ?? null;

        // Idempotency: if this exact source reference was already posted, return it.
        if ($sourceModule !== null && $sourceRefType !== null && $sourceRefId !== null) {
            $existing = $this->entryModel->findBySourceReference($sourceModule, $sourceRefType, (int)$sourceRefId);
            if ($existing) {
                return ['id' => (int)$existing['id'], 'entry_number' => $existing['entry_number'], 'created' => false];
            }
        }

        foreach ($lines as $line) {
            $account = $this->accountModel->findActive((int)$line['account_id']);
            if (!$account) {
                throw new InvalidArgumentException("Account id {$line['account_id']} does not exist or is not active.");
            }
        }

        // Resolve and validate accounting period (Step 4: enforces period controls)
        $entryDate = $request['entry_date'];
        $resolvedPeriodId = $this->validateAndResolvePeriod($entryDate, $request['accounting_period_id'] ?? null);

        // Nested transaction support: only manage transaction if we're not inside one already
        $ownTransaction = !$this->db->inTransaction();

        try {
            if ($ownTransaction) {
                $this->db->beginTransaction();
            }

            $entryNumber = $this->nextEntryNumber('JE');

            $entryId = $this->entryModel->create([
                'entry_number'          => $entryNumber,
                'entry_date'            => $entryDate,
                'financial_year_id'     => $request['financial_year_id'] ?? null,
                'accounting_period_id'  => $resolvedPeriodId, // Use resolved period
                'source_module'         => $sourceModule,
                'source_reference_type' => $sourceRefType,
                'source_reference_id'   => $sourceRefId,
                'description'           => $request['description'] ?? null,
                'status'                => 1,
                'created_by'            => $request['created_by'],
                'posted'                => 1,
                'reversed'              => 0,
            ]);
            if ($entryId === false) {
                throw new RuntimeException('Failed to insert journal entry.');
            }

            $this->lineModel->createBulk($entryId, $lines);

            $this->writeAudit((int)$request['created_by'], 'post', 'journal_entry', $entryId, [
                'entry_number' => $entryNumber,
                'entry_date'   => $request['entry_date'],
                'lines'        => $lines,
            ]);

            if ($ownTransaction) {
                $this->db->commit();
            }

            return ['id' => $entryId, 'entry_number' => $entryNumber, 'created' => true];
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // A concurrent request may have won the race on uk_source_reference —
            // re-check and return the entry it created instead of failing.
            if ($sourceModule !== null && $sourceRefType !== null && $sourceRefId !== null) {
                $existing = $this->entryModel->findBySourceReference($sourceModule, $sourceRefType, (int)$sourceRefId);
                if ($existing) {
                    return ['id' => (int)$existing['id'], 'entry_number' => $existing['entry_number'], 'created' => false];
                }
            }
            throw $e;
        }
    }

    private function validateLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A journal entry needs at least 2 lines.');
        }

        $debitCents  = 0;
        $creditCents = 0;

        foreach ($lines as $line) {
            $debit  = (float)($line['debit'] ?? 0);
            $credit = (float)($line['credit'] ?? 0);

            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException('Debit and credit amounts must not be negative.');
            }
            if (($debit > 0 && $credit > 0) || ($debit == 0 && $credit == 0)) {
                throw new InvalidArgumentException('Each line must be single-sided: exactly one of debit/credit greater than zero.');
            }
            if (empty($line['account_id'])) {
                throw new InvalidArgumentException('Every line requires an account_id.');
            }

            $debitCents  += (int)round($debit * 100);
            $creditCents += (int)round($credit * 100);
        }

        if ($debitCents !== $creditCents) {
            $d = number_format($debitCents / 100, 2);
            $c = number_format($creditCents / 100, 2);
            throw new InvalidArgumentException("Entry is not balanced: total debits {$d} != total credits {$c}.");
        }
    }

    /**
     * Validate and resolve accounting period for journal posting.
     * 
     * If accounting_period_id is provided, validates it is open and matches the entry_date.
     * If not provided, attempts to find an appropriate open period for the entry_date.
     * 
     * @param string $entryDate Entry date in Y-m-d format
     * @param int|null $accountingPeriodId Optional explicit period ID
     * @return int|null Validated or resolved period ID
     * @throws InvalidArgumentException if period is invalid, closed, or no valid period exists
     */
    private function validateAndResolvePeriod(string $entryDate, ?int $accountingPeriodId): ?int
    {
        // If no period specified, try to find one
        if ($accountingPeriodId === null) {
            $stmt = $this->db->prepare("
                SELECT 
                    ap.id, ap.status, ap.start_date, ap.end_date,
                    fy.status as fy_status
                FROM `accounting_periods` ap
                LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
                WHERE ap.status = 'open'
                  AND ap.start_date <= ?
                  AND ap.end_date >= ?
                  AND (fy.status = 'active' OR fy.status IS NULL)
                LIMIT 1
            ");
            $stmt->execute([$entryDate, $entryDate]);
            $period = $stmt->fetch();
            
            if (!$period) {
                throw new InvalidArgumentException(
                    "No open accounting period found for date {$entryDate}. " .
                    "Please create an accounting period covering this date."
                );
            }
            
            return (int)$period['id'];
        }

        // Period was explicitly provided - validate it
        $stmt = $this->db->prepare("
            SELECT 
                ap.status, ap.start_date, ap.end_date, ap.financial_year_id,
                fy.status as fy_status
            FROM `accounting_periods` ap
            LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
            WHERE ap.id = ?
        ");
        $stmt->execute([$accountingPeriodId]);
        $period = $stmt->fetch();
        
        if (!$period) {
            throw new InvalidArgumentException("Accounting period id {$accountingPeriodId} does not exist.");
        }
        
        if ($period['status'] !== 'open') {
            throw new InvalidArgumentException("Accounting period id {$accountingPeriodId} is closed.");
        }
        
        // Validate entry date is within period
        if ($entryDate < $period['start_date'] || $entryDate > $period['end_date']) {
            throw new InvalidArgumentException(
                "Entry date {$entryDate} is outside the period range " .
                "{$period['start_date']} to {$period['end_date']}."
            );
        }
        
        // Validate financial year is active (if present)
        if ($period['fy_status'] && $period['fy_status'] !== 'active') {
            throw new InvalidArgumentException(
                "The financial year for period {$accountingPeriodId} is not active."
            );
        }
        
        return $accountingPeriodId;
    }

    private function validatePeriodOpen(?int $accountingPeriodId): void
    {
        if ($accountingPeriodId === null) {
            // No period supplied — this is now deprecated behavior
            // New code should resolve periods, but we maintain backward compatibility
            return;
        }
        $stmt = $this->db->prepare("SELECT status FROM `accounting_periods` WHERE id = ? LIMIT 1");
        $stmt->execute([$accountingPeriodId]);
        $period = $stmt->fetch();
        if (!$period) {
            throw new InvalidArgumentException("Accounting period id {$accountingPeriodId} does not exist.");
        }
        if ($period['status'] !== 'open') {
            throw new InvalidArgumentException("Accounting period id {$accountingPeriodId} is closed.");
        }
    }

    private function nextEntryNumber(string $prefix): string
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
        return sprintf('%s%05d', $prefix, $next);
    }

    private function writeAudit(int $userId, string $action, string $entityType, int $entityId, array $afterData): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `journal_entry_audit` (user_id, action, entity_type, entity_id, before_json, after_json, reason, ip_address)
             VALUES (?, ?, ?, ?, NULL, ?, NULL, ?)"
        );
        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            json_encode($afterData),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    /**
     * Reverse a posted journal entry by creating a mirror-image reversal entry.
     * The original entry is never mutated — reversals are tracked via reversal_of_id.
     *
     * @param int $journalEntryId The ID of the journal entry to reverse
     * @param int $userId The user performing the reversal
     * @param string $reason The reason for reversal (required for audit trail)
     * @return array{id:int, entry_number:string, original_id:int, reversed:bool}
     * @throws InvalidArgumentException If entry doesn't exist, has no lines, or is already reversed
     */
    public function reverse(int $journalEntryId, int $userId, string $reason): array
    {
        // Validate reason
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reversal reason is required.');
        }

        // Load original journal entry
        $original = $this->entryModel->find($journalEntryId);
        if (!$original) {
            throw new InvalidArgumentException("Journal entry id {$journalEntryId} does not exist.");
        }

        // Stage 13-F (AUTH-D-02): shared self-reversal block.
        //
        // journal_entries.created_by is the one field that is reliably the
        // original maker/processor for EVERY caller of this method --
        // verified by reading each call site rather than assumed:
        //   - WithdrawalModel::reverseWithdrawal(): created_by == the same
        //     $userId processAnnualCompulsory()/processVoluntary() pass to
        //     post() (identical to withdrawals.processed_by/savings.recorded_by
        //     for that same transaction).
        //   - SavingsModel::delete(): created_by == the $userId postDeposit()/
        //     recordWithdrawalWithPosting() pass to post() (identical to
        //     savings.recorded_by).
        //   - RepaymentModel::delete(): created_by == the $userId
        //     postRepaymentJournal() passes to post() (identical to
        //     loan_repayments.received_by).
        //   - LoanModel::delete(): created_by == the $userId postDisbursement()
        //     passes to post() (identical to loans.disbursed_by).
        //   - MemberAccountAdjustmentModel::reverse(): created_by == the
        //     $userId post() itself passed to JournalService::post() -- the
        //     ONLY record of who actually posted; recorded_by/approved_by on
        //     the adjustment row describe the preparer/approver, not
        //     necessarily the poster.
        //   - ControlledCorrectionService::executeCorrection(): created_by ==
        //     whoever originally posted the (possibly historical) journal
        //     entry now being corrected -- exactly the actor separation of
        //     duties this control is meant to enforce.
        // $userId is never sourced from request input by any caller --
        // every controller in this codebase reads it from
        // Session::get('user_id') before calling into this chain, never
        // from $_GET/$_POST/JSON.
        //
        // This check is deliberately the very first thing done after
        // confirming the entry exists (before loading lines, before
        // checking already-reversed, before building anything) so a
        // blocked attempt causes zero further reads and, critically, zero
        // writes -- no journal mutation, no partial state. reverse() itself
        // never opens its own transaction (every one of the six callers
        // above already has one open by the time reverse() runs), so
        // there is no "pre-transaction" phase distinct from this one in
        // which a DB-backed audit log write could survive a subsequent
        // rollback by the caller -- deliberately using error_log() here
        // instead of a journal_entry_audit/activity_logs INSERT, exactly
        // to avoid a write that the caller's own catch-and-rollback would
        // silently erase. No password/token/secret is ever included.
        if ((int)($original['created_by'] ?? 0) === $userId) {
            error_log(sprintf(
                'SECURITY [AUTH-D-02]: self-reversal blocked — user #%d attempted to reverse journal entry #%d (%s), which they themselves originally posted. Reversal reason given: %s',
                $userId, $journalEntryId, $original['entry_number'] ?? '?', $reason
            ));
            throw new InvalidArgumentException('You cannot reverse a transaction that you yourself originally created or processed. Ask another authorized user to perform this reversal.');
        }

        // Load original lines
        $originalLines = $this->lineModel->forEntry($journalEntryId);
        if (empty($originalLines)) {
            throw new InvalidArgumentException("Journal entry id {$journalEntryId} has no lines.");
        }

        // Check if already reversed (using reversal_of_id, not legacy reversed field)
        $stmt = $this->db->prepare(
            "SELECT id, entry_number FROM `journal_entries` WHERE reversal_of_id = ? LIMIT 1"
        );
        $stmt->execute([$journalEntryId]);
        $existingReversal = $stmt->fetch();
        
        if ($existingReversal) {
            return [
                'id' => (int)$existingReversal['id'],
                'entry_number' => $existingReversal['entry_number'],
                'original_id' => $journalEntryId,
                'reversed' => false, // Already reversed, not newly created
            ];
        }

        // Build mirror-image lines (swap debits and credits)
        $reversalLines = [];
        foreach ($originalLines as $line) {
            $reversalLines[] = [
                'account_id' => $line['account_id'],
                'debit' => (float)$line['credit'],  // Original credit becomes reversal debit
                'credit' => (float)$line['debit'],  // Original debit becomes reversal credit
                'description' => 'Reversal of ' . $original['entry_number'] . 
                                 ($line['description'] ? ' — ' . $line['description'] : ''),
            ];
        }

        // Post the reversal using the standard posting path
        // IMPORTANT: Use today's date for reversal, NOT the original entry date
        // This allows reversals of closed-period entries to post into current open period
        $reversalDate = date('Y-m-d');
        
        $result = $this->post([
            'entry_date' => $reversalDate,
            'description' => "Reversal of {$original['entry_number']} — {$reason}",
            'source_module' => 'reversal',
            'source_reference_type' => 'journal_entry',
            'source_reference_id' => $journalEntryId,
            'financial_year_id' => $original['financial_year_id'],
            // Do NOT pass accounting_period_id — let post() resolve it based on reversal date
            'created_by' => $userId,
            'lines' => $reversalLines,
        ]);

        // Update the reversal entry to link back to original via reversal_of_id
        $this->db->prepare(
            "UPDATE `journal_entries` SET reversal_of_id = ? WHERE id = ?"
        )->execute([$journalEntryId, $result['id']]);

        // Write reversal-specific audit with reason
        $stmt = $this->db->prepare(
            "INSERT INTO `journal_entry_audit` 
             (user_id, action, entity_type, entity_id, before_json, after_json, reason, ip_address)
             VALUES (?, 'reverse', 'journal_entry', ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $userId,
            $journalEntryId, // Audit points to the ORIGINAL entry being reversed
            json_encode($original),
            json_encode([
                'reversal_entry_id' => $result['id'],
                'reversal_entry_number' => $result['entry_number'],
                'lines_count' => count($reversalLines),
            ]),
            $reason,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        return [
            'id' => $result['id'],
            'entry_number' => $result['entry_number'],
            'original_id' => $journalEntryId,
            'reversed' => true, // Newly created reversal
        ];
    }
}
