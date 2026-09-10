<?php
/**
 * ShareModel — Shares Module, Stage 1 (Foundation & Read-Only Workspace).
 *
 * Reads/writes `share_transactions`, the Shares ownership/history ledger.
 * This is NOT a second accounting engine and NOT a competing balance-of-
 * record: GL 3010 (Shares — Share Capital), posted exclusively via
 * JournalService, remains the sole financial authority. This model exists
 * to answer "who owns how many shares, and why" for reporting/ownership-
 * percentage purposes.
 *
 * `share_transactions` is not yet populated by any write path in Stage 1
 * (a live-insert hook on retained withdrawals would require modifying
 * WithdrawalModel.php, which this stage's change control explicitly
 * excludes; direct purchases are a later, separately-approved stage). Every
 * read method below therefore MERGES two sources so no second source of
 * truth is created and no double-counting is possible once a future stage
 * does start populating rows here:
 *   1. existing `withdrawals` rows with retained_amount > 0, EXCLUDING any
 *      that already have a mirroring `share_transactions` row (matched via
 *      source_reference_type='withdrawal' / source_reference_id), and
 *   2. `share_transactions` rows directly.
 *
 * KNOWN LIMITATION (documented, not silently assumed): a `withdrawals` row
 * has no per-transaction historical share_value column, so its derived
 * quantity is necessarily computed using the CURRENT settings.share_value
 * at read time. This is only a live historical-accuracy concern once
 * BOTH (a) settings.share_value has actually changed at least once AND
 * (b) real retained-withdrawal rows exist from before that change — neither
 * is true today (production has zero withdrawals). Any row that lives in
 * `share_transactions` itself always uses its own stored, transaction-time
 * share_value, per approved policy (no retroactive revaluation).
 */
class ShareModel extends Model
{
    protected string $table      = 'share_transactions';
    protected string $primaryKey = 'id';

    /**
     * Stage 2: the single authoritative string identifying a retained-
     * withdrawal mirror row's source table. Used both when WRITING
     * source_reference_type (mirrorRetainedWithdrawal(), and by
     * WithdrawalModel::processAnnualCompulsory() indirectly through it)
     * and when READING it (unmirroredWithdrawalWhere()) -- a single shared
     * constant so a typo becomes a PHP fatal (undefined constant) rather
     * than a silent double-counting bug, per the Stage 1-A audit's Low
     * finding. Deliberately just a string constant, not a class/enum --
     * the audit's own instruction was "simple string-discipline, not
     * refactoring."
     */
    public const SOURCE_WITHDRAWAL = 'withdrawal';

    /**
     * Stage 4-C: payment_method -> debit-side cash/bank GL account id.
     * Deliberately its own private copy, not a shared reference to
     * WithdrawalModel::PAYMENT_ACCOUNTS -- that constant is private, and
     * every other model in this codebase that needs this mapping
     * (SavingsModel, WithdrawalModel, ExpenseModel) already keeps its own
     * identical copy rather than a cross-model dependency; this follows
     * that same, already-established convention rather than introducing a
     * new one. Values re-verified live against the accounts table at
     * implementation time, not copied blind from an earlier report.
     */
    private const PAYMENT_ACCOUNTS = [
        'Cash'              => 7,  // 1110 Cash at Hand
        'MTN Mobile Money'  => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Airtel Money'      => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Bank Transfer'     => 10, // 1140 Bank Accounts
        'Cheque'            => 10, // 1140 Bank Accounts
        'Other'             => 7,  // fallback: Cash at Hand
    ];

    /** Shares (Share Capital) — GL 3010, same account id already used by
     *  WithdrawalModel::SHARE_CAPITAL_ACCOUNT for the Stage 2 retained-
     *  withdrawal mirror. */
    private const SHARE_CAPITAL_ACCOUNT = 24;

    /** Quantity sign by transaction type -- only retained_withdrawal and
     *  direct_purchase/transfer_in/adjustment rows can exist as of Stage 1
     *  (no write path yet creates any row at all), but every type is
     *  handled here so a later stage's writes are correctly netted without
     *  needing a matching change in this file. */
    private const NEGATIVE_TYPES = ['transfer_out', 'redemption'];

    private function signedQuantityExpr(): string
    {
        $negative = "'" . implode("','", self::NEGATIVE_TYPES) . "'";
        return "SUM(CASE WHEN transaction_type IN ($negative) THEN -quantity ELSE quantity END)";
    }

    private function signedAmountExpr(): string
    {
        $negative = "'" . implode("','", self::NEGATIVE_TYPES) . "'";
        return "SUM(CASE WHEN transaction_type IN ($negative) THEN -amount ELSE amount END)";
    }

    /** Retained-withdrawal quantity/amount not yet mirrored into
     *  share_transactions -- the "source 1" half of every merge below. */
    private function unmirroredWithdrawalWhere(): string
    {
        $source = self::SOURCE_WITHDRAWAL;
        return "w.retained_amount > 0 AND w.id NOT IN (
            SELECT source_reference_id FROM share_transactions
            WHERE source_reference_type = '$source' AND source_reference_id IS NOT NULL
        )";
    }

    /**
     * Stage 2 — mirrors one already-finalized retained-withdrawal event
     * into the share ledger. Called by WithdrawalModel::
     * processAnnualCompulsory() AFTER the withdrawal row and its journal
     * entry already exist and are linked, from WITHIN that same still-open
     * database transaction (Database::getInstance() is a singleton, so
     * this model's PDO connection IS that same connection -- no separate
     * transaction is opened here, none should be).
     *
     * Does NOT call JournalService, does NOT recalculate retained_amount,
     * and does NOT floor/round the quantity to a whole share -- it only
     * records the ALREADY-authoritative withdrawal figures as one ledger
     * row. `journal_entry_id` is copied from the withdrawal's own already-
     * posted entry, never a new posting. The database's own
     * uq_share_source unique constraint (source_reference_type,
     * source_reference_id) is the final, authoritative duplicate guard --
     * a second call for the same withdrawal throws (PDO::ERRMODE_EXCEPTION
     * is set app-wide), which propagates up through
     * processAnnualCompulsory()'s existing try/catch and rolls back the
     * ENTIRE withdrawal, exactly matching this app's established
     * atomicity convention rather than silently swallowing the error.
     *
     * @param array{member_id:int,transaction_date:string,amount:float,
     *              payment_method:?string,source_reference_id:int,
     *              journal_entry_id:int,processed_by:int,
     *              reference_number:?string} $data
     * @return int the new share_transactions row's id
     */
    public function mirrorRetainedWithdrawal(array $data): int
    {
        $shareValue = (float)(new SettingsModel())->get('share_value', '20000');
        $amount     = round((float)$data['amount'], 2);
        $quantity   = $shareValue > 0 ? round($amount / $shareValue, 4) : 0.0;

        $id = $this->create([
            'member_id'              => (int)$data['member_id'],
            'transaction_type'       => 'retained_withdrawal',
            'transaction_date'       => $data['transaction_date'],
            'quantity'               => $quantity,
            'share_value'            => $shareValue,
            'amount'                 => $amount,
            'payment_method'         => $data['payment_method'] ?? null,
            'reference_number'       => $data['reference_number'] ?? null,
            'source_reference_type'  => self::SOURCE_WITHDRAWAL,
            'source_reference_id'    => (int)$data['source_reference_id'],
            'journal_entry_id'       => (int)$data['journal_entry_id'],
            'processed_by'           => (int)$data['processed_by'],
        ]);
        if ($id === false) {
            throw new RuntimeException('Failed to mirror the retained withdrawal into the share ledger.');
        }
        return $id;
    }

    public function totalIssuedQuantity(float $shareValue): float
    {
        try {
            $where = $this->unmirroredWithdrawalWhere();
            $stmt = $this->db->query(
                "SELECT COALESCE(SUM(w.retained_amount),0) FROM `withdrawals` w WHERE $where"
            );
            $fromWithdrawals = $shareValue > 0 ? ((float)$stmt->fetchColumn() / $shareValue) : 0.0;

            $signed = $this->signedQuantityExpr();
            $fromLedger = (float)$this->db->query(
                "SELECT COALESCE($signed,0) FROM `share_transactions`"
            )->fetchColumn();

            return $fromWithdrawals + $fromLedger;
        } catch (PDOException $e) { return 0.0; }
    }

    public function totalShareCapital(): float
    {
        try {
            $where = $this->unmirroredWithdrawalWhere();
            $fromWithdrawals = (float)$this->db->query(
                "SELECT COALESCE(SUM(w.retained_amount),0) FROM `withdrawals` w WHERE $where"
            )->fetchColumn();

            $signed = $this->signedAmountExpr();
            $fromLedger = (float)$this->db->query(
                "SELECT COALESCE($signed,0) FROM `share_transactions`"
            )->fetchColumn();

            return $fromWithdrawals + $fromLedger;
        } catch (PDOException $e) { return 0.0; }
    }

    public function memberQuantity(int $memberId, float $shareValue): float
    {
        try {
            $where = $this->unmirroredWithdrawalWhere();
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(w.retained_amount),0) FROM `withdrawals` w
                 WHERE w.member_id = ? AND $where"
            );
            $stmt->execute([$memberId]);
            $fromWithdrawals = $shareValue > 0 ? ((float)$stmt->fetchColumn() / $shareValue) : 0.0;

            $signed = $this->signedQuantityExpr();
            $stmt2 = $this->db->prepare(
                "SELECT COALESCE($signed,0) FROM `share_transactions` WHERE member_id = ?"
            );
            $stmt2->execute([$memberId]);
            $fromLedger = (float)$stmt2->fetchColumn();

            return $fromWithdrawals + $fromLedger;
        } catch (PDOException $e) { return 0.0; }
    }

    public function memberCapital(int $memberId): float
    {
        try {
            $where = $this->unmirroredWithdrawalWhere();
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(w.retained_amount),0) FROM `withdrawals` w
                 WHERE w.member_id = ? AND $where"
            );
            $stmt->execute([$memberId]);
            $fromWithdrawals = (float)$stmt->fetchColumn();

            $signed = $this->signedAmountExpr();
            $stmt2 = $this->db->prepare(
                "SELECT COALESCE($signed,0) FROM `share_transactions` WHERE member_id = ?"
            );
            $stmt2->execute([$memberId]);
            $fromLedger = (float)$stmt2->fetchColumn();

            return $fromWithdrawals + $fromLedger;
        } catch (PDOException $e) { return 0.0; }
    }

    /** Distinct members with a non-zero share position, merged across both sources. */
    public function shareholderCount(): int
    {
        try {
            $where = $this->unmirroredWithdrawalWhere();
            $stmt = $this->db->query(
                "SELECT COUNT(DISTINCT member_id) FROM (
                    SELECT w.member_id FROM `withdrawals` w WHERE $where
                    UNION
                    SELECT member_id FROM `share_transactions`
                 ) x"
            );
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    /** Top shareholders by total share capital, merged across both sources. */
    public function topShareholders(float $shareValue, int $limit = 20): array
    {
        try {
            $where = $this->unmirroredWithdrawalWhere();
            $signedQty = $this->signedQuantityExpr();
            $signedAmt = $this->signedAmountExpr();
            $limit = max(1, $limit);

            $stmt = $this->db->query(
                "SELECT m.id, m.first_name, m.last_name, m.member_number,
                        COALESCE(wsum.total_amount,0) + COALESCE(lsum.total_amount,0) AS total_capital,
                        (COALESCE(wsum.total_amount,0) / NULLIF($shareValue,0)) + COALESCE(lsum.total_qty,0) AS total_quantity
                 FROM `members` m
                 LEFT JOIN (
                     SELECT w.member_id, SUM(w.retained_amount) AS total_amount
                     FROM `withdrawals` w WHERE $where GROUP BY w.member_id
                 ) wsum ON wsum.member_id = m.id
                 LEFT JOIN (
                     SELECT member_id, $signedAmt AS total_amount, $signedQty AS total_qty
                     FROM `share_transactions` GROUP BY member_id
                 ) lsum ON lsum.member_id = m.id
                 HAVING total_capital > 0
                 ORDER BY total_capital DESC
                 LIMIT $limit"
            );
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    /**
     * Chronological share ledger for one member, merging unmirrored
     * retained-withdrawal rows with any share_transactions rows, with a
     * running quantity/capital balance computed the same way
     * StatementModel::shareLedger() already computes its running balance
     * (a PHP loop over chronologically-sorted rows, not a SQL window
     * function) -- kept consistent with that existing convention.
     */
    public function ledgerForMember(int $memberId, float $shareValue): array
    {
        try {
            $where = $this->unmirroredWithdrawalWhere();
            $stmt = $this->db->prepare(
                "SELECT w.withdrawal_date AS transaction_date, w.withdrawal_number AS reference_number,
                        'retained_withdrawal' AS transaction_type, 'withdrawal' AS source_reference_type,
                        w.retained_amount AS amount, w.journal_entry_id, w.id AS sort_id,
                        NULL AS row_quantity, NULL AS row_share_value
                 FROM `withdrawals` w
                 WHERE w.member_id = ? AND $where
                 UNION ALL
                 SELECT st.transaction_date, COALESCE(st.reference_number, CONCAT('ST-', st.id)),
                        st.transaction_type, st.source_reference_type,
                        CASE WHEN st.transaction_type IN ('transfer_out','redemption') THEN -st.amount ELSE st.amount END AS amount,
                        st.journal_entry_id, st.id,
                        CASE WHEN st.transaction_type IN ('transfer_out','redemption') THEN -st.quantity ELSE st.quantity END AS row_quantity,
                        st.share_value AS row_share_value
                 FROM `share_transactions` st
                 WHERE st.member_id = ?
                 ORDER BY transaction_date ASC, sort_id ASC"
            );
            // Stage 3 fix: the legacy (Stage 1) version of this query always
            // recomputed quantity as amount/$shareValue (the CURRENT share
            // value) for EVERY row, including share_transactions rows that
            // already store their OWN historical quantity/share_value --
            // silently discarding exactly the historical-accuracy guarantee
            // this module promises. Rows selected from `withdrawals` (which
            // has no historical share_value column at all -- the
            // already-documented Stage 1 limitation) still use the current
            // $shareValue passed in, via row_quantity/row_share_value being
            // NULL for that half of the UNION; rows selected from
            // `share_transactions` now always use their own stored values.
            $stmt->execute([$memberId, $memberId]);

            $runningQty = 0.0;
            $runningCapital = 0.0;
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $amount = (float)$r['amount'];
                $hasOwnValues = $r['row_quantity'] !== null && $r['row_share_value'] !== null;
                $qty        = $hasOwnValues ? (float)$r['row_quantity']    : ($shareValue > 0 ? ($amount / $shareValue) : 0.0);
                $rowShareVal = $hasOwnValues ? (float)$r['row_share_value'] : $shareValue;
                $runningQty += $qty;
                $runningCapital += $amount;
                $rows[] = [
                    'date'                  => $r['transaction_date'],
                    'reference_number'      => $r['reference_number'],
                    'transaction_type'      => $r['transaction_type'],
                    'source_reference_type' => $r['source_reference_type'],
                    'quantity'              => $qty,
                    'share_value'           => $rowShareVal,
                    'amount'                => $amount,
                    'journal_entry_id'      => $r['journal_entry_id'],
                    'running_quantity'      => $runningQty,
                    'running_capital'       => $runningCapital,
                ];
            }
            return $rows;
        } catch (PDOException $e) { return []; }
    }

    /** member_net_quantity / organization_net_quantity * 100 -- always
     *  computed live, never stored (approved policy). Returns 0.0 when
     *  no shares are issued, rather than dividing by zero. */
    public static function ownershipPercentage(float $memberQuantity, float $totalIssuedQuantity): float
    {
        if ($totalIssuedQuantity <= 0) {
            return 0.0;
        }
        return round(($memberQuantity / $totalIssuedQuantity) * 100, 2);
    }

    // ================================================================
    // STAGE 3 — HISTORICAL / OPENING SHARE ENTRY
    //
    // Ownership/ledger records ONLY (Option A, per the Stage 3 brief
    // Section 9) -- these shares already existed before this module went
    // live, so NEITHER method below calls JournalService, and
    // journal_entry_id is always left NULL. Posting a normal Cash/Bank ->
    // Share Capital entry for historical data would be actively wrong: no
    // cash is being received today. GL 3010 reconciliation for pre-existing
    // share capital is a separate, already-tracked opening-balance
    // initiative for this club (out of scope here, per the brief).
    // ================================================================

    private const HISTORICAL_RETAINED_REFERENCE = 'Opening — Retained Savings';
    private const HISTORICAL_PURCHASE_REFERENCE = 'Opening — Bought Shares';

    /** Historical entries are dated in the past by definition -- a future
     *  date could never represent an already-existing share position. */
    private function isValidHistoricalDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date && $date <= date('Y-m-d');
    }

    /**
     * Deliberately soft duplicate strategy (brief Section 11): a unique DB
     * constraint on (member_id, transaction_type, transaction_date, amount)
     * would incorrectly forbid a club that genuinely has two separate
     * historical records landing on the same date for the same amount.
     * Instead, an exact match is flagged back to the caller, which must
     * pass confirm_duplicate=true to proceed anyway -- mirrors the existing
     * "resend anyway" checkbox pattern already used in this codebase
     * (StatementController's bulk email, force_resend).
     */
    private function hasLikelyDuplicate(int $memberId, string $type, string $date, float $amount): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM `share_transactions`
             WHERE member_id = ? AND transaction_type = ? AND transaction_date = ? AND amount = ?"
        );
        $stmt->execute([$memberId, $type, $date, $amount]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function log(int $userId, string $action, string $description): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `activity_logs`(`user_id`,`action`,`description`,`ip_address`)
                 VALUES (?,?,?,?)"
            );
            $stmt->execute([$userId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (PDOException $e) {}
    }

    /**
     * Historical Retained Savings entry: the user supplies the retained
     * AMOUNT (never the quantity); this method derives quantity from the
     * currently-configured settings.share_value at the moment of entry and
     * stores both, exactly like Stage 2's mirrorRetainedWithdrawal() --
     * never floored, never re-derived from a later settings change.
     *
     * @throws InvalidArgumentException on any validation failure (message
     *         prefixed "DUPLICATE:" specifically for the soft duplicate
     *         check, so the controller can re-render with a confirmation
     *         checkbox rather than a hard error).
     */
    public function createHistoricalRetained(array $data, int $userId): int
    {
        $memberId         = (int)($data['member_id'] ?? 0);
        $date             = trim((string)($data['transaction_date'] ?? ''));
        $amount           = round((float)($data['retained_amount'] ?? 0), 2);
        $confirmDuplicate = !empty($data['confirm_duplicate']);

        if ($memberId < 1 || !(new MemberModel())->find($memberId)) {
            throw new InvalidArgumentException('Select a valid member.');
        }
        if (!$this->isValidHistoricalDate($date)) {
            throw new InvalidArgumentException('Enter a valid historical date (it cannot be in the future).');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Retained amount must be greater than zero.');
        }

        $shareValue = (float)(new SettingsModel())->get('share_value', '20000');
        $quantity   = $shareValue > 0 ? round($amount / $shareValue, 4) : 0.0;

        if (!$confirmDuplicate && $this->hasLikelyDuplicate($memberId, 'opening_retained', $date, $amount)) {
            throw new InvalidArgumentException('DUPLICATE: An identical historical retained-savings entry (same member, date, and amount) already exists. Confirm to add it anyway.');
        }

        $id = $this->create([
            'member_id'              => $memberId,
            'transaction_type'       => 'opening_retained',
            'transaction_date'       => $date,
            'quantity'               => $quantity,
            'share_value'            => $shareValue,
            'amount'                 => $amount,
            'payment_method'         => null,
            'reference_number'       => self::HISTORICAL_RETAINED_REFERENCE,
            'source_reference_type'  => null,
            'source_reference_id'    => null,
            'journal_entry_id'       => null,
            'processed_by'           => $userId,
        ]);
        if ($id === false) {
            throw new RuntimeException('Failed to record the historical retained-savings entry.');
        }
        $this->log($userId, 'share_historical_retained_entry', sprintf(
            'Historical Retained Savings entry for member #%d: UGX %s retained at share value UGX %s = %s shares (historical date %s)',
            $memberId, number_format($amount, 2), number_format($shareValue, 2), number_format($quantity, 4), $date
        ));
        return $id;
    }

    /**
     * Historical Bought Shares entry: the user supplies the QUANTITY
     * (whole shares only -- validated server-side, never trusting a
     * client-side calculation); this method derives the monetary amount
     * from the currently-configured settings.share_value.
     *
     * @throws InvalidArgumentException on any validation failure.
     */
    public function createHistoricalPurchase(array $data, int $userId): int
    {
        $memberId         = (int)($data['member_id'] ?? 0);
        $date             = trim((string)($data['transaction_date'] ?? ''));
        $quantityRaw      = $data['quantity'] ?? null;
        $confirmDuplicate = !empty($data['confirm_duplicate']);

        if ($memberId < 1 || !(new MemberModel())->find($memberId)) {
            throw new InvalidArgumentException('Select a valid member.');
        }
        if (!$this->isValidHistoricalDate($date)) {
            throw new InvalidArgumentException('Enter a valid historical date (it cannot be in the future).');
        }
        if (!is_numeric($quantityRaw) || (float)$quantityRaw != (int)$quantityRaw || (int)$quantityRaw <= 0) {
            throw new InvalidArgumentException('Number of shares must be a whole number greater than zero.');
        }
        $quantity = (int)$quantityRaw;

        $shareValue = (float)(new SettingsModel())->get('share_value', '20000');
        $amount     = round($quantity * $shareValue, 2);

        if (!$confirmDuplicate && $this->hasLikelyDuplicate($memberId, 'opening_purchase', $date, $amount)) {
            throw new InvalidArgumentException('DUPLICATE: An identical historical purchased-shares entry (same member, date, and amount) already exists. Confirm to add it anyway.');
        }

        $id = $this->create([
            'member_id'              => $memberId,
            'transaction_type'       => 'opening_purchase',
            'transaction_date'       => $date,
            'quantity'               => $quantity,
            'share_value'            => $shareValue,
            'amount'                 => $amount,
            'payment_method'         => null,
            'reference_number'       => self::HISTORICAL_PURCHASE_REFERENCE,
            'source_reference_type'  => null,
            'source_reference_id'    => null,
            'journal_entry_id'       => null,
            'processed_by'           => $userId,
        ]);
        if ($id === false) {
            throw new RuntimeException('Failed to record the historical purchased-shares entry.');
        }
        $this->log($userId, 'share_historical_purchase_entry', sprintf(
            'Historical Bought Shares entry for member #%d: %s shares at UGX %s = UGX %s (historical date %s)',
            $memberId, number_format($quantity, 0), number_format($shareValue, 2), number_format($amount, 2), $date
        ));
        return $id;
    }

    // ================================================================
    // STAGE 4-C — CURRENT SHARE TRANSACTION RECORDING
    //
    // Records a real-world share contribution that has ALREADY occurred
    // and been confirmed by staff outside Empower (business model
    // preserved from the Stage 4/4-B audits: Empower never collects,
    // initiates, or processes the payment itself). Unlike Stage 3's
    // historical entries, a current transaction IS journal-posted -- the
    // real-world money movement already happened, so the accounting
    // consequence is recorded now, exactly like any other "staff records
    // an externally-confirmed payment" workflow in this codebase
    // (SavingsModel::postDeposit() is the direct template reused below).
    // ================================================================

    private const CURRENT_TRANSACTION_SOURCE_MODULE = 'shares';
    private const CURRENT_TRANSACTION_SOURCE_TYPE   = 'direct_purchase';

    /**
     * Stage 4-C sequence generator for SHR-######. Deliberately copies
     * SavingsModel::nextCashReference()'s exact `%s-%06d` shape (hyphen,
     * 6 digits) -- NOT JournalService::nextEntryNumber()'s different
     * `%s%05d` shape, which is specific to JE entry numbers only (see the
     * Stage 4-B audit's §10 finding). Same row-locked
     * journal_number_sequences + FOR UPDATE pattern already proven safe
     * for 22 other prefixes: the increment happens inside whatever
     * transaction is already open when this is called (never a separate
     * one of its own), so a rollback of the caller's transaction also
     * rolls back this UPDATE -- a failed current transaction never
     * consumes a real SHR number.
     */
    private function nextShrReference(): string
    {
        $stmt = $this->db->prepare("SELECT last_number FROM `journal_number_sequences` WHERE prefix = 'SHR' FOR UPDATE");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No journal_number_sequences row for prefix 'SHR'.");
        }
        $next = (int)$row['last_number'] + 1;
        $this->db->prepare("UPDATE `journal_number_sequences` SET last_number = ? WHERE prefix = 'SHR'")
            ->execute([$next]);
        return sprintf('SHR-%06d', $next);
    }

    /**
     * Stronger duplicate signal than hasLikelyDuplicate(): a non-empty
     * external_reference (a real-world Mobile Money/bank/cheque reference)
     * repeated for the SAME member is close-to-conclusive evidence of an
     * accidental double-entry, per the Stage 4-B audit's §11/§14
     * recommendation -- checked and reported separately from the base
     * member+type+date+amount check so the controller can show a more
     * specific warning.
     */
    private function hasExternalReferenceDuplicate(int $memberId, string $externalReference): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM `share_transactions` WHERE member_id = ? AND external_reference = ?"
        );
        $stmt->execute([$memberId, $externalReference]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Records one current share transaction: validates and normalizes
     * every input server-side (never trusting a client-supplied quantity,
     * share_value, reference_number, transaction_type, journal_entry_id,
     * processed_by, or source_reference_* value -- none of those are even
     * accepted as input here), generates the internal SHR-###### reference,
     * inserts the row, posts the ONE corresponding journal entry via
     * JournalService::post() (Dr the payment-method's mapped account,
     * Cr GL 3010 Share Capital), links journal_entry_id back onto the row,
     * and writes one activity-log entry -- all inside a single database
     * transaction, following the exact $ownTransaction guard already
     * established by WithdrawalModel::processAnnualCompulsory() and
     * SavingsModel::postDeposit(), so a nested caller's own transaction is
     * never accidentally committed or rolled back by this method.
     *
     * @param array{member_id:int,transaction_date:string,amount:mixed,
     *              payment_method:string,external_reference:?string,
     *              confirm_duplicate:?bool,confirm_external_duplicate:?bool} $data
     * @return array{id:int,reference_number:string,journal_entry_id:int,
     *               quantity:float,share_value:float,amount:float}
     * @throws InvalidArgumentException on any validation failure. A soft
     *         duplicate is signalled via a message prefixed "DUPLICATE:"
     *         or "EXTERNAL_DUPLICATE:" so the controller can re-render
     *         with the appropriate confirmation checkbox rather than a
     *         hard error, exactly like Stage 3's historical-entry flow.
     */
    public function createCurrentTransaction(array $data, int $userId): array
    {
        $memberId    = (int)($data['member_id'] ?? 0);
        $date        = trim((string)($data['transaction_date'] ?? ''));
        $amountRaw   = $data['amount'] ?? null;
        $paymentMethod = (string)($data['payment_method'] ?? '');
        $externalReference = trim((string)($data['external_reference'] ?? ''));
        $externalReference = $externalReference === '' ? null : mb_substr($externalReference, 0, 100);
        $confirmDuplicate         = !empty($data['confirm_duplicate']);
        $confirmExternalDuplicate = !empty($data['confirm_external_duplicate']);

        if ($memberId < 1 || !(new MemberModel())->find($memberId)) {
            throw new InvalidArgumentException('Select a valid member.');
        }
        // isValidHistoricalDate() is shared with Stage 3 despite its name --
        // both a historical entry and a current transaction represent an
        // event that has already occurred, so "not blank, valid Y-m-d,
        // never in the future" is the correct rule for both.
        if (!$this->isValidHistoricalDate($date)) {
            throw new InvalidArgumentException('Enter a valid transaction date (it cannot be in the future).');
        }
        if (!is_numeric($amountRaw)) {
            throw new InvalidArgumentException('Amount received must be a valid number.');
        }
        $amount = round((float)$amountRaw, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount received must be greater than zero.');
        }
        if (!isset(self::PAYMENT_ACCOUNTS[$paymentMethod])) {
            throw new InvalidArgumentException("Invalid payment method \"{$paymentMethod}\".");
        }

        $cashAccount = (new AccountModel())->findActive(self::PAYMENT_ACCOUNTS[$paymentMethod]);
        if (!$cashAccount) {
            throw new InvalidArgumentException("The cash/bank account for payment method \"{$paymentMethod}\" does not exist or is inactive.");
        }
        $sharesAccount = (new AccountModel())->findActive(self::SHARE_CAPITAL_ACCOUNT);
        if (!$sharesAccount) {
            throw new InvalidArgumentException('The Shares (Share Capital) account does not exist or is inactive.');
        }

        $shareValue = (float)(new SettingsModel())->get('share_value', '20000');
        if ($shareValue <= 0) {
            throw new InvalidArgumentException('The configured share value is invalid.');
        }
        $quantity = round($amount / $shareValue, 4);

        if (!$confirmDuplicate && $this->hasLikelyDuplicate($memberId, self::CURRENT_TRANSACTION_SOURCE_TYPE, $date, $amount)) {
            throw new InvalidArgumentException('DUPLICATE: An identical share contribution (same member, date, and amount) already exists. Confirm to add it anyway.');
        }
        if ($externalReference !== null && !$confirmExternalDuplicate && $this->hasExternalReferenceDuplicate($memberId, $externalReference)) {
            throw new InvalidArgumentException('EXTERNAL_DUPLICATE: This external reference has already been recorded for this member. Confirm to add it anyway.');
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $reference = $this->nextShrReference();

            $id = $this->create([
                'member_id'              => $memberId,
                'transaction_type'       => self::CURRENT_TRANSACTION_SOURCE_TYPE,
                'transaction_date'       => $date,
                'quantity'               => $quantity,
                'share_value'            => $shareValue,
                'amount'                 => $amount,
                'payment_method'         => $paymentMethod,
                'reference_number'       => $reference,
                'external_reference'     => $externalReference,
                'source_reference_type'  => null,
                'source_reference_id'    => null,
                'journal_entry_id'       => null,
                'processed_by'           => $userId,
            ]);
            if ($id === false) {
                throw new RuntimeException('Failed to record the share transaction.');
            }

            // Accounting period resolution -- identical pattern to
            // WithdrawalModel::processAnnualCompulsory()/SavingsModel::postDeposit().
            $periodStmt = $this->db->prepare("
                SELECT ap.id AS period_id, ap.financial_year_id
                FROM `accounting_periods` ap
                LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
                WHERE ap.status = 'open'
                  AND ap.start_date <= ? AND ap.end_date >= ?
                  AND (fy.status = 'active' OR fy.status IS NULL)
                LIMIT 1
            ");
            $periodStmt->execute([$date, $date]);
            $period = $periodStmt->fetch();

            $member = (new MemberModel())->find($memberId);
            $memberLabel = $member ? trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) : ('member #' . $memberId);

            $service = new JournalService();
            $result = $service->post([
                'entry_date'            => $date,
                'description'           => "Share contribution {$reference} — {$memberLabel}",
                'source_module'         => self::CURRENT_TRANSACTION_SOURCE_MODULE,
                'source_reference_type' => self::CURRENT_TRANSACTION_SOURCE_TYPE,
                'source_reference_id'   => $id,
                'financial_year_id'     => $period['financial_year_id'] ?? null,
                'accounting_period_id'  => $period['period_id'] ?? null,
                'created_by'            => $userId,
                'lines'                 => [
                    ['account_id' => $cashAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => $paymentMethod],
                    ['account_id' => $sharesAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => 'Share Capital contribution'],
                ],
            ]);

            $this->update($id, ['journal_entry_id' => $result['id']]);

            $this->log($userId, 'share_current_transaction_recorded', sprintf(
                'Current share transaction %s for member #%d: UGX %s received via %s at share value UGX %s = %s shares',
                $reference, $memberId, number_format($amount, 2), $paymentMethod, number_format($shareValue, 2), number_format($quantity, 4)
            ));

            if ($ownTransaction) {
                $this->db->commit();
            }

            return [
                'id'                => $id,
                'reference_number'  => $reference,
                'journal_entry_id'  => (int)$result['id'],
                'quantity'          => $quantity,
                'share_value'       => $shareValue,
                'amount'            => $amount,
            ];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
