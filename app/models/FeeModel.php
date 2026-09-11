<?php
/**
 * FeeModel — Fees & Charges Management
 */
class FeeModel extends Model
{
    protected string $table      = 'fees';
    protected string $primaryKey = 'id';

    /** payment_method -> debit-side cash/bank GL account id (same mapping as LoanModel/SavingsModel/RepaymentModel/ExpenseModel) */
    private const PAYMENT_ACCOUNTS = [
        'Cash'              => 7,  // 1110 Cash at Hand
        'MTN Mobile Money'  => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Airtel Money'      => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Bank Transfer'     => 10, // 1140 Bank Accounts
        'Cheque'            => 10, // 1140 Bank Accounts
        'Other'             => 7,  // fallback: Cash at Hand
    ];

    /** fees.frequency -> credit-side income account id (Stage 16A mapping, confirmed against the live Chart of Accounts) */
    private const FEE_INCOME_ACCOUNTS = [
        'one_time' => 37, // 4090 Membership / Registration Fees
        'annual'   => 39, // 4110 Annual Subscription Fees
        'per_loan' => 38, // 4100 Loan Processing Fees
    ];

    /**
     * fees.frequency -> Cash Reference prefix. Reuses the SAME
     * already-authoritative classification the line above uses for real
     * GL account routing -- `frequency` is not merely "how often this
     * recurs," it is already treated as this system's de facto fee-type
     * identity (forensic audit: FEE_INCOME_ACCOUNTS has routed real money
     * to distinct GL accounts by this exact field since Stage 16A). No new
     * column was introduced; this is the smallest change consistent with
     * an already-reliable field, not fragile string matching on fee_name.
     *
     * 'per_loan' (Loan Processing Fee) is deliberately absent -- no Cash
     * Reference prefix was approved for it; a Cash payment of it receives
     * no cash_reference_number, matching the approved 6-prefix design.
     * A future fee added with an unmapped frequency also safely defaults
     * to no Cash Reference, rather than an accidental new prefix.
     */
    private const FEE_CASH_REFERENCE_PREFIX = [
        'one_time' => 'CHR', // Registration Fee
        'annual'   => 'CHA', // Annual Subscription Fee
    ];

    // ================================================================
    // FEE CONFIGURATION
    // ================================================================

    public function getAllFees(): array
    {
        try {
            return $this->db->query("SELECT * FROM `fees` ORDER BY `id`")->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function getActiveFees(): array
    {
        try {
            return $this->db->query("SELECT * FROM `fees` WHERE `is_active`=1 ORDER BY `id`")->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function getFeeByFrequency(string $frequency): array|false
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM `fees` WHERE `frequency`=? AND `is_active`=1 LIMIT 1");
            $stmt->execute([$frequency]);
            return $stmt->fetch();
        } catch (PDOException $e) { return false; }
    }

    public function createFee(array $data): int|false
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `fees` (`fee_name`,`fee_type`,`amount`,`frequency`,`description`,`effective_date`,`is_active`)
                 VALUES (?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $data['fee_name'], $data['fee_type'], $data['amount'],
                $data['frequency'], $data['description'] ?? null,
                $data['effective_date'] ?? date('Y-m-d'), $data['is_active'] ?? 1
            ]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) { return false; }
    }

    public function updateFee(int $id, array $data): bool
    {
        try {
            $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($data)));
            $values = array_values($data);
            $values[] = $id;
            $stmt = $this->db->prepare("UPDATE `fees` SET {$set} WHERE `id`=?");
            return $stmt->execute($values);
        } catch (PDOException $e) { return false; }
    }

    public function toggleFee(int $id): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE `fees` SET `is_active`=IF(`is_active`=1,0,1) WHERE `id`=?");
            $stmt->execute([$id]);
        } catch (PDOException $e) {}
    }

    public function deleteFee(int $id): bool
    {
        try {
            // Only delete if never used
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `member_fees` WHERE `fee_id`=?");
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() > 0) return false;
            $stmt = $this->db->prepare("DELETE FROM `fees` WHERE `id`=?");
            return $stmt->execute([$id]);
        } catch (PDOException $e) { return false; }
    }

    public function recordHistory(int $feeId, ?float $oldAmount, ?float $newAmount, int $userId, string $remarks = ''): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `fee_history` (`fee_id`,`old_amount`,`new_amount`,`changed_by`,`remarks`) VALUES (?,?,?,?,?)"
            );
            $stmt->execute([$feeId, $oldAmount, $newAmount, $userId, $remarks]);
        } catch (PDOException $e) {}
    }

    // ================================================================
    // CHARGE MEMBERS
    // ================================================================

    /**
     * FEE- internal transaction number. Previously an unsafe MAX()+1 read
     * with no database uniqueness constraint at all (forensic audit
     * CRITICAL finding) -- now migrated onto the same row-locked
     * journal_number_sequences + FOR UPDATE mechanism already proven safe
     * elsewhere in this codebase. Public method name/signature unchanged,
     * so none of its 4 existing callers (chargeRegistrationFee(),
     * chargeAnnualSubscription(), chargeLoanProcessingFee(), the manual
     * chargeMember() path) needed to change.
     */
    public function generateReference(): string
    {
        return $this->nextSequenceNumber('FEE');
    }

    /**
     * Shared row-locked sequence generator, reused for both the hardened
     * FEE- internal number above and the new CHR-/CHA- Cash Reference
     * numbers below -- both live in journal_number_sequences, so one
     * implementation correctly serves both within this model.
     */
    private function nextSequenceNumber(string $prefix): string
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

    /**
     * Charge a fee to a member. Now wraps generateReference() + the
     * insert in an explicit transaction (previously absent) so the
     * FOR UPDATE lock taken inside generateReference() is genuinely held
     * across the whole operation -- required for the hardened generator
     * above to actually be safe under concurrency; a lock released before
     * the row it protects is inserted would defeat the whole mechanism.
     * catch(Throwable) (widened from catch(PDOException)) preserves this
     * method's existing "never throws, returns false" contract even
     * though generateReference() can now throw RuntimeException in the
     * (should-never-happen, since the migration seeds the FEE row)
     * case that the sequence row is missing.
     */
    public function chargeMember(int $memberId, int $feeId, float $amount, ?int $financialYearId = null, ?int $loanId = null, ?int $createdBy = null): int|false
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $ref = $this->generateReference();
            $stmt = $this->db->prepare(
                "INSERT INTO `member_fees` (`member_id`,`fee_id`,`amount`,`status`,`reference_number`,`financial_year_id`,`loan_id`,`charged_date`,`created_by`)
                 VALUES (?,?,?,'pending',?,?,?,CURDATE(),?)"
            );
            $stmt->execute([$memberId, $feeId, $amount, $ref, $financialYearId, $loanId, $createdBy]);
            $id = (int)$this->db->lastInsertId();
            if ($ownTransaction) {
                $this->db->commit();
            }
            return $id;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    /**
     * Charge registration fee to a new member (prevents duplicates).
     *
     * Stage 12-G: now returns the new `member_fees` id (or null when this
     * was a no-op -- fee type not configured, or already charged) so a
     * caller can notify the fee-collection tier about a genuinely new
     * charge. Purely additive -- the return value was previously silently
     * discarded (method was `void`); every existing call site that
     * ignores it is completely unaffected. No calculation, amount, or
     * duplicate-prevention logic changed.
     */
    public function chargeRegistrationFee(int $memberId, ?int $createdBy = null): ?int
    {
        $fee = $this->getFeeByFrequency('one_time');
        if (!$fee) return null;

        // Check if already charged
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `member_fees` WHERE `member_id`=? AND `fee_id`=?");
        $stmt->execute([$memberId, $fee['id']]);
        if ((int)$stmt->fetchColumn() > 0) return null;

        $id = $this->chargeMember($memberId, (int)$fee['id'], (float)$fee['amount'], null, null, $createdBy);
        return $id !== false ? $id : null;
    }

    /**
     * Charge annual subscription to a member (prevents duplicates per FY).
     * Stage 12-G: same additive return-value change as chargeRegistrationFee() above.
     */
    public function chargeAnnualSubscription(int $memberId, ?int $financialYearId = null, ?int $createdBy = null): ?int
    {
        $fee = $this->getFeeByFrequency('annual');
        if (!$fee) return null;

        // financial_year_id is a real FK into financial_years.id (e.g. 2,
        // not a literal calendar year like 2026) -- resolve the active
        // financial year's actual id rather than fabricating one.
        $activeFy = (new FinancialYearModel())->findWhere(['status' => 'active']);
        $fyId = $financialYearId ?? ($activeFy ? (int)$activeFy['id'] : null);

        // Check if already charged this FY
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM `member_fees` WHERE `member_id`=? AND `fee_id`=? AND `financial_year_id`=?"
        );
        $stmt->execute([$memberId, $fee['id'], $fyId]);
        if ((int)$stmt->fetchColumn() > 0) return null;

        $id = $this->chargeMember($memberId, (int)$fee['id'], (float)$fee['amount'], $fyId, null, $createdBy);
        return $id !== false ? $id : null;
    }

    /**
     * Calculate and charge loan processing fee.
     * Stage 12-G: same additive return-value change as chargeRegistrationFee() above.
     */
    public function chargeLoanProcessingFee(int $memberId, int $loanId, float $loanAmount, ?int $createdBy = null): ?int
    {
        $fee = $this->getFeeByFrequency('per_loan');
        if (!$fee) return null;

        // Check if already charged for this loan
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `member_fees` WHERE `loan_id`=? AND `fee_id`=?");
        $stmt->execute([$loanId, $fee['id']]);
        if ((int)$stmt->fetchColumn() > 0) return null;

        $feeAmount = ($fee['fee_type'] === 'percentage')
            ? round($loanAmount * ($fee['amount'] / 100), 2)
            : (float)$fee['amount'];

        $id = $this->chargeMember($memberId, (int)$fee['id'], $feeAmount, null, $loanId, $createdBy);
        return $id !== false ? $id : null;
    }

    /**
     * Fees a staff member (Treasurer, Cashier, Office Administrator, Admin
     * -- the same tier as markPaid()) can select and apply to a member on
     * demand, e.g. an annual subscription. Deliberately excludes:
     *   - frequency='per_loan' -- always auto-charged at loan disbursement
     *     (LoanController::handleSave() -> chargeLoanProcessingFee()),
     *     tied to a specific loan_id this screen has no context for.
     *   - fee_type='percentage' -- meaningless without a base amount
     *     (percentage fees only ever apply to a loan amount today).
     */
    public function getManuallyChargeableFees(): array
    {
        try {
            return $this->db->query(
                "SELECT * FROM `fees` WHERE `is_active`=1 AND `frequency` != 'per_loan' AND `fee_type`='fixed' ORDER BY `fee_name`"
            )->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    /**
     * Manually charge a specific fee to a member -- the counterpart to the
     * automatic chargeRegistrationFee()/chargeAnnualSubscription() calls,
     * for cases those don't cover (recharging annual subscription for a
     * specific member on request, one-off fees, etc.). Applies the same
     * duplicate-prevention discipline as those methods, generalized to
     * whichever fee was actually selected rather than assuming there is
     * only one fee per frequency.
     */
    public function manualCharge(int $memberId, int $feeId, ?int $createdBy = null): int
    {
        $fee = $this->find($feeId);
        if (!$fee || !$fee['is_active']) {
            throw new InvalidArgumentException('Selected fee does not exist or is not active.');
        }
        if ($fee['frequency'] === 'per_loan') {
            throw new InvalidArgumentException('Loan processing fees are charged automatically when a loan is disbursed and cannot be recorded manually here.');
        }
        if ($fee['fee_type'] === 'percentage') {
            throw new InvalidArgumentException('Percentage-based fees require a loan amount and cannot be recorded manually here.');
        }

        $fyId = null;
        if ($fee['frequency'] === 'annual') {
            $activeFy = (new FinancialYearModel())->findWhere(['status' => 'active']);
            $fyId = $activeFy ? (int)$activeFy['id'] : null;
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM `member_fees` WHERE `member_id`=? AND `fee_id`=? AND `financial_year_id`" . ($fyId !== null ? '=?' : ' IS NULL')
            );
            $stmt->execute($fyId !== null ? [$memberId, $feeId, $fyId] : [$memberId, $feeId]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new InvalidArgumentException('This member has already been charged this fee for the current financial year.');
            }
        } elseif ($fee['frequency'] === 'one_time') {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `member_fees` WHERE `member_id`=? AND `fee_id`=?");
            $stmt->execute([$memberId, $feeId]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new InvalidArgumentException('This member has already been charged this one-time fee.');
            }
        } elseif ($fee['frequency'] === 'monthly') {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM `member_fees` WHERE `member_id`=? AND `fee_id`=? AND DATE_FORMAT(charged_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"
            );
            $stmt->execute([$memberId, $feeId]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new InvalidArgumentException('This member has already been charged this fee for the current month.');
            }
        }

        $id = $this->chargeMember($memberId, $feeId, (float)$fee['amount'], $fyId, null, $createdBy);
        if ($id === false) {
            throw new RuntimeException('Failed to record the fee charge.');
        }
        return $id;
    }

    /**
     * Charge a fee and immediately mark it paid, as one atomic operation --
     * for the common front-desk case where a member pays a fee (e.g. Annual
     * Subscription) on the spot, rather than being billed now and paying
     * later. Reuses manualCharge() and markPaid() completely unchanged
     * (same duplicate-fee rules, same account resolution, same
     * JournalService::post() posting, same double-payment guard) -- this
     * method only adds the transaction wrapper around both calls, mirroring
     * the same create-then-post pattern SavingsModel::
     * recordDepositWithPosting() already uses for deposits. If the charge
     * succeeds but the payment step then fails for any reason (inactive
     * account, closed accounting period, invalid payment method), the
     * whole operation rolls back -- no orphaned pending charge is left
     * behind. The separate manual "charge only" path (manualCharge()
     * alone, no payment method) and the separate "Mark Paid" action on an
     * already-existing pending charge are both untouched by this method.
     *
     * @return array{member_fee_id:int, journal_entry_id:int, entry_number:string, created:bool, cash_reference_number:?string}
     */
    public function chargeAndMarkPaid(int $memberId, int $feeId, int $userId, string $paymentMethod, ?string $externalReference = null): array
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $memberFeeId = $this->manualCharge($memberId, $feeId, $userId);
            $posted = $this->markPaid($memberFeeId, $paymentMethod, $userId, $externalReference);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return array_merge(['member_fee_id' => $memberFeeId], $posted);
    }

    // ================================================================
    // PAYMENT & STATUS
    // ================================================================

    /**
     * Mark a pending member_fees charge paid and post it through
     * JournalService::post(), both inside one atomic transaction (Stage 17):
     * Dr <cash/bank account for the payment method> / Cr <the fee's
     * frequency-mapped income account>. Cash-basis recognition -- a fee
     * never posts while merely 'pending' (see Stage 16A). Idempotent: a
     * charge that is not currently 'pending' is left untouched and no
     * second journal can ever be created for the same charge, since
     * JournalService::post() enforces uniqueness on
     * (source_module, source_reference_type, source_reference_id) at the
     * database level.
     *
     * @throws InvalidArgumentException if the charge doesn't exist, isn't
     *         pending, or the payment method is invalid.
     */
    /**
     * Stage D (approved Stage C, G1): $externalReference is an optional,
     * staff-supplied payment-provider reference (a mobile-money code, a
     * bank deposit slip number, a cheque number, ...) -- entirely
     * separate from $charge['reference_number'] (the permanent,
     * system-generated FEE-###### charge/obligation reference, never
     * touched here) and from the CHR-/CHA- cash_reference_number
     * generated below (also untouched). Trimmed to a plain string or
     * NULL; never validated against any format, since it is an external
     * party's reference, not one this system generates or controls.
     * Stored only in the same UPDATE that flips status to 'paid' --
     * never written for a rejected/pending charge.
     */
    public function markPaid(int $memberFeeId, string $paymentMethod, int $userId, ?string $externalReference = null): array
    {
        $externalReference = $externalReference !== null ? trim($externalReference) : null;
        if ($externalReference === '') {
            $externalReference = null;
        }

        if (!array_key_exists($paymentMethod, self::PAYMENT_ACCOUNTS)) {
            throw new InvalidArgumentException("Invalid payment method \"{$paymentMethod}\".");
        }

        $stmt = $this->db->prepare(
            "SELECT mf.*, f.fee_name, f.frequency, m.first_name, m.last_name
             FROM `member_fees` mf
             JOIN `fees` f ON f.id = mf.fee_id
             JOIN `members` m ON m.id = mf.member_id
             WHERE mf.id = ? LIMIT 1"
        );
        $stmt->execute([$memberFeeId]);
        $charge = $stmt->fetch();
        if (!$charge) {
            throw new InvalidArgumentException("Fee charge id {$memberFeeId} does not exist.");
        }
        if ($charge['status'] !== 'pending') {
            throw new InvalidArgumentException("Fee charge id {$memberFeeId} is not pending (current status: {$charge['status']}).");
        }

        $incomeAccountId = self::FEE_INCOME_ACCOUNTS[$charge['frequency']] ?? null;
        if ($incomeAccountId === null) {
            throw new InvalidArgumentException("No income account is mapped for fee frequency \"{$charge['frequency']}\".");
        }

        $debitAccount = (new AccountModel())->findActive(self::PAYMENT_ACCOUNTS[$paymentMethod]);
        if (!$debitAccount) {
            throw new InvalidArgumentException("The cash/bank account for payment method \"{$paymentMethod}\" does not exist or is inactive.");
        }
        $incomeAccount = (new AccountModel())->findActive($incomeAccountId);
        if (!$incomeAccount) {
            throw new InvalidArgumentException("The income account for fee frequency \"{$charge['frequency']}\" does not exist or is inactive.");
        }

        $amount = (float)$charge['amount'];
        $paidDate = date('Y-m-d');

        $periodStmt = $this->db->prepare("
            SELECT ap.id AS period_id, ap.financial_year_id
            FROM `accounting_periods` ap
            LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
            WHERE ap.status = 'open'
              AND ap.start_date <= ? AND ap.end_date >= ?
              AND (fy.status = 'active' OR fy.status IS NULL)
            LIMIT 1
        ");
        $periodStmt->execute([$paidDate, $paidDate]);
        $period = $periodStmt->fetch();

        $memberLabel = trim($charge['first_name'] . ' ' . $charge['last_name']);

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $service = new JournalService();
            $result = $service->post([
                'entry_date'            => $paidDate,
                'description'           => "Fee {$charge['reference_number']} ({$charge['fee_name']}) — {$memberLabel}",
                'source_module'         => 'member_fees',
                'source_reference_type' => 'fee_payment',
                'source_reference_id'   => $memberFeeId,
                'financial_year_id'     => $period['financial_year_id'] ?? null,
                'accounting_period_id'  => $period['period_id'] ?? null,
                'created_by'            => $userId,
                'lines'                 => [
                    ['account_id' => $debitAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => $paymentMethod],
                    ['account_id' => $incomeAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => $charge['fee_name']],
                ],
            ]);

            // Cash Reference (CHR-/CHA-) -- server-computed here, never
            // taken from caller/client input. Only Registration Fee
            // ('one_time') and Annual Subscription ('annual') have an
            // approved prefix; any other fee frequency (e.g. Loan
            // Processing Fee, 'per_loan') correctly receives NULL even
            // when paid in Cash, per the approved 6-prefix design.
            $cashReferenceNumber = null;
            if ($paymentMethod === 'Cash') {
                $cashPrefix = self::FEE_CASH_REFERENCE_PREFIX[$charge['frequency']] ?? null;
                if ($cashPrefix !== null) {
                    $cashReferenceNumber = $this->nextSequenceNumber($cashPrefix);
                }
            }

            $this->db->prepare(
                "UPDATE `member_fees` SET `status`='paid', `paid_date`=?, `payment_method`=?, `cash_reference_number`=?, `external_reference`=?, `journal_entry_id`=? WHERE `id`=?"
            )->execute([$paidDate, $paymentMethod, $cashReferenceNumber, $externalReference, $result['id'], $memberFeeId]);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return ['journal_entry_id' => $result['id'], 'entry_number' => $result['entry_number'], 'created' => $result['created'], 'cash_reference_number' => $cashReferenceNumber];
    }

    public function markWaived(int $memberFeeId): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE `member_fees` SET `status`='waived', `paid_date`=CURDATE() WHERE `id`=?");
            $stmt->execute([$memberFeeId]);
        } catch (PDOException $e) {}
    }

    public function markCancelled(int $memberFeeId): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE `member_fees` SET `status`='cancelled' WHERE `id`=?");
            $stmt->execute([$memberFeeId]);
        } catch (PDOException $e) {}
    }

    // ================================================================
    // QUERIES & REPORTS
    // ================================================================

    public function getMemberFees(int $memberId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT mf.*, f.fee_name, f.fee_type, f.frequency
                 FROM `member_fees` mf
                 JOIN `fees` f ON f.id = mf.fee_id
                 WHERE mf.member_id=?
                 ORDER BY mf.charged_date DESC"
            );
            $stmt->execute([$memberId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function getMemberOutstandingTotal(int $memberId): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(amount),0) FROM `member_fees` WHERE `member_id`=? AND `status`='pending'"
            );
            $stmt->execute([$memberId]);
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    public function searchCharges(string $search = '', string $status = '', int $feeId = 0, int $page = 1, int $perPage = 20): array
    {
        try {
            $where = [];
            $params = [];

            if ($search !== '') {
                $like = '%' . $search . '%';
                $where[] = '(m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_number LIKE ? OR mf.reference_number LIKE ?)';
                array_push($params, $like, $like, $like, $like);
            }
            if ($status !== '') { $where[] = 'mf.status = ?'; $params[] = $status; }
            if ($feeId > 0)    { $where[] = 'mf.fee_id = ?'; $params[] = $feeId; }

            $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $offset = ($page - 1) * $perPage;

            $from = "FROM `member_fees` mf
                     JOIN `members` m ON m.id = mf.member_id
                     JOIN `fees` f ON f.id = mf.fee_id
                     {$whereSQL}";

            $countStmt = $this->db->prepare("SELECT COUNT(*) {$from}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $listStmt = $this->db->prepare(
                "SELECT mf.*, m.first_name, m.last_name, m.member_number, f.fee_name, f.fee_type, f.frequency
                 {$from} ORDER BY mf.charged_date DESC, mf.id DESC LIMIT ? OFFSET ?"
            );
            $i = 1;
            foreach ($params as $v) { $listStmt->bindValue($i++, $v); }
            $listStmt->bindValue($i++, $perPage, PDO::PARAM_INT);
            $listStmt->bindValue($i, $offset, PDO::PARAM_INT);
            $listStmt->execute();

            return ['rows' => $listStmt->fetchAll(), 'total' => $total, 'pages' => max(1, (int)ceil($total / $perPage))];
        } catch (PDOException $e) {
            return ['rows' => [], 'total' => 0, 'pages' => 1];
        }
    }

    public function todayCollections(): float
    {
        try {
            return (float)$this->db->query(
                "SELECT COALESCE(SUM(amount),0) FROM `member_fees` WHERE `status`='paid' AND `paid_date` = CURDATE()"
            )->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    public function getReportSummary(): array
    {
        try {
            $report = [];
            $report['registration_collected'] = (float)$this->db->query(
                "SELECT COALESCE(SUM(mf.amount),0) FROM member_fees mf JOIN fees f ON f.id=mf.fee_id WHERE f.frequency='one_time' AND mf.status='paid'"
            )->fetchColumn();
            $report['annual_collected'] = (float)$this->db->query(
                "SELECT COALESCE(SUM(mf.amount),0) FROM member_fees mf JOIN fees f ON f.id=mf.fee_id WHERE f.frequency='annual' AND mf.status='paid'"
            )->fetchColumn();
            $report['loan_fees_collected'] = (float)$this->db->query(
                "SELECT COALESCE(SUM(mf.amount),0) FROM member_fees mf JOIN fees f ON f.id=mf.fee_id WHERE f.frequency='per_loan' AND mf.status='paid'"
            )->fetchColumn();
            $report['total_outstanding'] = (float)$this->db->query(
                "SELECT COALESCE(SUM(amount),0) FROM member_fees WHERE status='pending'"
            )->fetchColumn();
            $report['total_collected'] = (float)$this->db->query(
                "SELECT COALESCE(SUM(amount),0) FROM member_fees WHERE status='paid'"
            )->fetchColumn();
            return $report;
        } catch (PDOException $e) {
            return ['registration_collected'=>0,'annual_collected'=>0,'loan_fees_collected'=>0,'total_outstanding'=>0,'total_collected'=>0];
        }
    }

    // ================================================================
    // ACTIVITY LOG
    // ================================================================

    public function log(int $userId, string $action, string $desc = ''): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `activity_logs`(`user_id`,`action`,`description`,`ip_address`) VALUES (?,?,?,?)"
            );
            $stmt->execute([$userId, $action, $desc, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (PDOException $e) {}
    }
}
