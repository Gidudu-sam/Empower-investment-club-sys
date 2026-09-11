<?php
/**
 * SavingsModel
 */
class SavingsModel extends Model
{
    protected string $table      = 'savings';
    protected string $primaryKey = 'id';

    // ----------------------------------------------------------------
    // Receipt number generation  SAV-000001
    // ----------------------------------------------------------------
    public function generateReceiptNumber(): string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT MAX(CAST(SUBSTRING(`receipt_number`, 5) AS UNSIGNED)) AS max_seq
                 FROM `savings`"
            );
            $stmt->execute();
            $row  = $stmt->fetch();
            $next = (int)($row['max_seq'] ?? 0) + 1;
            return 'SAV-' . str_pad($next, 6, '0', STR_PAD_LEFT);
        } catch (PDOException $e) {
            return 'SAV-000001';
        }
    }

    // ----------------------------------------------------------------
    // Cash Reference generation (CHS-000001) -- a NEW, separate identifier
    // from the receipt_number above. Populated only when payment_method
    // is Cash, on every savings row regardless of which caller inserts it
    // (deposit, withdrawal via the annual-compulsory/voluntary engines,
    // Fixed Deposit opening principal, closure settlement) since all of
    // them funnel through create() below. Uses the same row-locked
    // journal_number_sequences + FOR UPDATE pattern already proven safe
    // for 14 other prefixes -- never MAX()+1, never reused after a
    // reversal/deletion (the counter is monotonic and never decremented).
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

    // ----------------------------------------------------------------
    // Dashboard stats
    // ----------------------------------------------------------------
    public function totalSavings(): float
    {
        try {
            return (float)$this->db->query("SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM `savings`")->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    public function todayTotal(): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM `savings` WHERE `transaction_date` = CURDATE()"
            );
            $stmt->execute();
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    public function monthTotal(): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM `savings`
                 WHERE MONTH(transaction_date)=MONTH(CURDATE())
                   AND YEAR(transaction_date)=YEAR(CURDATE())"
            );
            $stmt->execute();
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    /**
     * Monthly deposit/withdrawal/growth breakdown for the last N months
     * (for the dashboard's combined bar+line chart). Deposits/withdrawals
     * are filtered by `transaction_type` specifically -- not derived from
     * raw debit/credit sign -- so an opening-balance or adjustment row
     * never gets miscounted as ordinary deposit/withdrawal activity.
     * `growth` is the cumulative net (deposits-withdrawals, all types)
     * running total RELATIVE TO THE START of this N-month window (i.e.
     * "how has the club's savings position moved over this period"), not
     * the account's full all-time balance -- the latter would already be
     * a huge absolute number and useless plotted next to monthly deltas.
     * Returns [['month'=>'2026-08','label'=>'Aug 2026','deposits'=>..,
     *           'withdrawals'=>..,'growth'=>..], ...] ASC.
     */
    public function monthlyDepositWithdrawalTrend(int $months = 12): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month,
                        SUM(CASE WHEN transaction_type = 'deposit' THEN COALESCE(credit,0) ELSE 0 END) AS deposits,
                        SUM(CASE WHEN transaction_type = 'withdrawal' THEN COALESCE(debit,0) ELSE 0 END) AS withdrawals,
                        SUM(COALESCE(credit,0) - COALESCE(debit,0)) AS net
                 FROM `savings`
                 WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                 GROUP BY month
                 ORDER BY month ASC"
            );
            $stmt->bindValue(1, $months, PDO::PARAM_INT);
            $stmt->execute();
            $byMonth = [];
            foreach ($stmt->fetchAll() as $r) { $byMonth[$r['month']] = $r; }

            // Zero-fill every month in the window, not just months that
            // happen to have a savings row -- a GROUP BY alone silently
            // drops any month with no activity at all (confirmed directly:
            // production currently has real data in only 4 of the last 12
            // months, and the raw query returned exactly 4 rows, not 12).
            // Deposits/withdrawals correctly show 0 for a quiet month;
            // growth correctly CARRIES FORWARD the prior month's cumulative
            // figure unchanged (no activity means the position didn't
            // move), never resets to 0.
            $rows = [];
            $cumulative = 0.0;
            $cursor = new DateTime('first day of this month');
            $cursor->modify('-' . ($months - 1) . ' months');
            for ($i = 0; $i < $months; $i++) {
                $key = $cursor->format('Y-m');
                $found = $byMonth[$key] ?? null;
                $cumulative += $found ? (float)$found['net'] : 0.0;
                $rows[] = [
                    'month'       => $key,
                    'label'       => $cursor->format('M Y'),
                    'deposits'    => $found ? (float)$found['deposits'] : 0.0,
                    'withdrawals' => $found ? (float)$found['withdrawals'] : 0.0,
                    'growth'      => $cumulative,
                ];
                $cursor->modify('+1 month');
            }

            return $rows;
        } catch (PDOException $e) { return []; }
    }

    /**
     * Monthly deposit totals for the last N months (for dashboard/report charts).
     * Returns [['month'=>'2026-08','label'=>'Aug 2026','total'=>123.45], ...] ASC.
     */
    public function monthlyTotals(int $months = 12): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month,
                        DATE_FORMAT(transaction_date, '%b %Y') AS label,
                        SUM(COALESCE(credit,0)-COALESCE(debit,0)) AS total
                 FROM `savings`
                 WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                 GROUP BY month, label
                 ORDER BY month ASC"
            );
            $stmt->bindValue(1, $months, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    /**
     * Savings collected in the current "collection week": Monday 11:00 AM
     * through the following Monday 10:00 AM (based on when the deposit was
     * actually recorded, not the user-entered transaction_date).
     */
    public function weekCollections(): array
    {
        [$start, $end] = self::currentCollectionWeek();
        $total = 0.0;
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM `savings`
                 WHERE created_at >= ? AND created_at < ?"
            );
            $stmt->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
            $total = (float)$stmt->fetchColumn();
        } catch (PDOException $e) {}

        return ['total' => $total, 'start' => $start, 'end' => $end];
    }

    /**
     * Window for the current collection week: [Monday 11:00 AM, next Monday 10:00 AM).
     */
    public static function currentCollectionWeek(): array
    {
        $now   = new \DateTime();
        $dow   = (int)$now->format('N'); // 1=Mon .. 7=Sun
        $start = (clone $now)->setTime(0, 0, 0)->modify('-' . ($dow - 1) . ' days')->setTime(11, 0, 0);
        if ($now < $start) {
            $start->modify('-7 days');
        }
        $end = (clone $start)->modify('+7 days')->setTime(10, 0, 0);
        return [$start, $end];
    }

    /**
     * Active members who have made no deposit within the given window
     * (default: the current collection week), with their contact details
     * and last-ever deposit for a follow-up/reminder list.
     */
    public function membersNotSavedInWindow(\DateTime $start, \DateTime $end): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT m.id, m.first_name, m.last_name, m.member_number, m.account_number, m.phone,
                        (SELECT (COALESCE(s2.credit,0)-COALESCE(s2.debit,0))
                           FROM `savings` s2 WHERE s2.member_id = m.id
                           ORDER BY s2.transaction_date DESC, s2.id DESC LIMIT 1) AS last_saved_amount,
                        (SELECT s2.transaction_date
                           FROM `savings` s2 WHERE s2.member_id = m.id
                           ORDER BY s2.transaction_date DESC, s2.id DESC LIMIT 1) AS last_saved_date
                 FROM `members` m
                 WHERE m.status = 'active'
                   AND NOT EXISTS (
                       SELECT 1 FROM `savings` s
                       WHERE s.member_id = m.id AND s.created_at >= ? AND s.created_at < ?
                   )
                 ORDER BY m.first_name ASC, m.last_name ASC"
            );
            $stmt->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    /**
     * Member with the highest current savings balance.
     */
    public function topSaver(): array|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT m.id, m.first_name, m.last_name, m.member_number,
                        SUM(COALESCE(s.credit,0)-COALESCE(s.debit,0)) AS balance
                 FROM `savings` s
                 JOIN `members` m ON m.id = s.member_id
                 GROUP BY m.id, m.first_name, m.last_name, m.member_number
                 ORDER BY balance DESC
                 LIMIT 1"
            );
            $stmt->execute();
            return $stmt->fetch();
        } catch (PDOException $e) { return false; }
    }

    public function recentTransactions(int $limit = 6): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT s.*, (COALESCE(s.credit,0)-COALESCE(s.debit,0)) AS amount,
                        m.first_name, m.last_name, m.member_number,
                        u.full_name AS cashier_name
                 FROM `savings` s
                 JOIN `members` m ON m.id = s.member_id
                 LEFT JOIN `users` u ON u.id = s.recorded_by
                 ORDER BY s.created_at DESC
                 LIMIT ?"
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    // ----------------------------------------------------------------
    // Member-specific stats (for member profile)
    // ----------------------------------------------------------------
    public function memberBalance(int $memberId): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM `savings` WHERE member_id = ?"
            );
            $stmt->execute([$memberId]);
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    public function memberDepositCount(int $memberId): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM `savings` WHERE member_id = ?"
            );
            $stmt->execute([$memberId]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    public function memberLatestDeposit(int $memberId): array|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT *, (COALESCE(credit,0)-COALESCE(debit,0)) AS amount FROM `savings` WHERE member_id = ?
                 ORDER BY transaction_date DESC, id DESC LIMIT 1"
            );
            $stmt->execute([$memberId]);
            return $stmt->fetch();
        } catch (PDOException $e) { return false; }
    }

    /**
     * Per-member month-end savings balance for the last N months (member
     * portal dashboard chart). Unlike monthlyDepositWithdrawalTrend()
     * (club-wide, relative-to-window-start), this seeds the running total
     * with the member's real balance immediately before the window, so
     * each point is their actual balance, not a window-relative delta.
     * Returns [['month'=>'2026-08','label'=>'Aug 2026','balance'=>123.45], ...] ASC.
     */
    public function memberMonthlyTrend(int $memberId, int $months = 6): array
    {
        try {
            $cursor = new DateTime('first day of this month');
            $cursor->modify('-' . ($months - 1) . ' months');
            $windowStart = $cursor->format('Y-m-d');

            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0)
                 FROM `savings` WHERE member_id = ? AND transaction_date < ?"
            );
            $stmt->execute([$memberId, $windowStart]);
            $balance = (float)$stmt->fetchColumn();

            $stmt = $this->db->prepare(
                "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month,
                        SUM(COALESCE(credit,0)-COALESCE(debit,0)) AS net
                 FROM `savings`
                 WHERE member_id = ? AND transaction_date >= ?
                 GROUP BY month
                 ORDER BY month ASC"
            );
            $stmt->execute([$memberId, $windowStart]);
            $byMonth = [];
            foreach ($stmt->fetchAll() as $r) { $byMonth[$r['month']] = (float)$r['net']; }

            $rows = [];
            for ($i = 0; $i < $months; $i++) {
                $key = $cursor->format('Y-m');
                $balance += $byMonth[$key] ?? 0.0;
                $rows[] = [
                    'month'   => $key,
                    'label'   => $cursor->format('M Y'),
                    'balance' => $balance,
                ];
                $cursor->modify('+1 month');
            }
            return $rows;
        } catch (PDOException $e) { return []; }
    }

    public function memberHistory(int $memberId, int $limit = 20): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT s.*, (COALESCE(s.credit,0)-COALESCE(s.debit,0)) AS amount,
                        u.full_name AS cashier_name
                 FROM `savings` s
                 LEFT JOIN `users` u ON u.id = s.recorded_by
                 WHERE s.member_id = ?
                 ORDER BY s.transaction_date DESC, s.id DESC
                 LIMIT ?"
            );
            $stmt->bindValue(1, $memberId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit,    PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    // ----------------------------------------------------------------
    // Single record with joined member + cashier
    // ----------------------------------------------------------------
    public function findWithDetails(int $id): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, (COALESCE(s.credit,0)-COALESCE(s.debit,0)) AS amount,
                    ABS(COALESCE(s.credit,0)-COALESCE(s.debit,0)) AS amount_abs,
                    m.first_name, m.last_name, m.member_number, m.phone AS member_phone,
                    u.full_name AS cashier_name,
                    je.entry_number,
                    a.account_number, a.account_type
             FROM `savings` s
             JOIN `members` m ON m.id = s.member_id
             LEFT JOIN `users` u ON u.id = s.recorded_by
             LEFT JOIN `journal_entries` je ON je.id = s.journal_entry_id
             LEFT JOIN `member_savings_accounts` a ON a.id = s.savings_account_id
             WHERE s.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /** payment_method -> debit-side cash/bank GL account id (same mapping as ExpenseModel::PAYMENT_ACCOUNTS) */
    private const PAYMENT_ACCOUNTS = [
        'Cash'              => 7,  // 1110 Cash at Hand
        'MTN Mobile Money'  => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Airtel Money'      => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Bank Transfer'     => 10, // 1140 Bank Accounts
        'Cheque'            => 10, // 1140 Bank Accounts
        'Other'             => 7,  // fallback: Cash at Hand
    ];

    /** Members' Savings liability account (2020) */
    private const SAVINGS_LIABILITY_ACCOUNT = 17;

    /**
     * Record a savings deposit and post it through JournalService::post(),
     * both inside one atomic transaction: Dr <cash/bank account> / Cr
     * Members' Savings. Never inserts into journal_entries/journal_lines
     * directly. If posting fails, the savings row itself rolls back too.
     */
    public function recordDepositWithPosting(array $input, int $userId): array
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $savingsId = $this->create($input);
            if ($savingsId === false) {
                throw new RuntimeException('Failed to record savings deposit.');
            }

            $result = $this->postDeposit($savingsId, $userId);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return array_merge(['id' => $savingsId], $result);
    }

    /**
     * Post an already-recorded savings deposit as a journal entry.
     * Idempotent: if this row is already linked to a journal entry, returns
     * that reference instead of posting again — safe to call more than once
     * for the same savings row (e.g. a retried request).
     */
    public function postDeposit(int $savingsId, int $userId): array
    {
        $saving = $this->find($savingsId);
        if (!$saving) {
            throw new InvalidArgumentException("Savings record id {$savingsId} does not exist.");
        }
        if (!empty($saving['journal_entry_id'])) {
            $je = $this->db->prepare("SELECT entry_number FROM journal_entries WHERE id = ?");
            $je->execute([$saving['journal_entry_id']]);
            return ['journal_entry_id' => (int)$saving['journal_entry_id'], 'entry_number' => $je->fetchColumn(), 'created' => false];
        }

        $creditAccountId = self::PAYMENT_ACCOUNTS[$saving['payment_method']] ?? 7;
        $creditAccount = (new AccountModel())->findActive($creditAccountId);
        if (!$creditAccount) {
            throw new InvalidArgumentException("The cash/bank account for payment method \"{$saving['payment_method']}\" does not exist or is inactive.");
        }
        $liabilityAccount = (new AccountModel())->findActive(self::SAVINGS_LIABILITY_ACCOUNT);
        if (!$liabilityAccount) {
            throw new InvalidArgumentException('The Members\' Savings liability account does not exist or is inactive.');
        }

        // JournalService::post() auto-resolves accounting_period_id when none
        // is passed, but takes financial_year_id as-is (defaults to NULL) --
        // resolve both explicitly here, same fix already made in
        // ExpenseModel::post() after Step 7 caught this gap via testing.
        $periodStmt = $this->db->prepare("
            SELECT ap.id AS period_id, ap.financial_year_id
            FROM `accounting_periods` ap
            LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
            WHERE ap.status = 'open'
              AND ap.start_date <= ? AND ap.end_date >= ?
              AND (fy.status = 'active' OR fy.status IS NULL)
            LIMIT 1
        ");
        $periodStmt->execute([$saving['transaction_date'], $saving['transaction_date']]);
        $period = $periodStmt->fetch();

        $amount = (float)$saving['amount'];

        $member = (new MemberModel())->find((int)$saving['member_id']);
        $memberLabel = $member ? trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) : ('member #' . $saving['member_id']);

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $service = new JournalService();
            $result = $service->post([
                'entry_date'             => $saving['transaction_date'],
                'description'            => "Savings deposit {$saving['receipt_number']} — {$memberLabel}",
                'source_module'          => 'savings',
                'source_reference_type'  => 'deposit',
                'source_reference_id'    => $savingsId,
                'financial_year_id'      => $period['financial_year_id'] ?? null,
                'accounting_period_id'   => $period['period_id'] ?? null,
                'created_by'             => $userId,
                'lines'                  => [
                    ['account_id' => $creditAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => $saving['payment_method']],
                    ['account_id' => $liabilityAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => 'Members\' Savings'],
                ],
            ]);

            $this->db->prepare("UPDATE `savings` SET journal_entry_id = ? WHERE id = ?")->execute([$result['id'], $savingsId]);

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

    /**
     * Stage 5B: record a savings-account withdrawal and post it through
     * JournalService::post(), mirroring recordDepositWithPosting() exactly
     * (same atomicity pattern, same accounting dependencies) but for the
     * reverse economic event: cash leaves the club, its liability to the
     * member/account shrinks. $input MUST include 'savings_account_id' --
     * enforced by the caller (SavingsAccountController), not here, per the
     * "enforce in the application path" instruction; this method itself
     * still independently re-checks available balance as a money-safety
     * invariant that must hold regardless of which caller reaches it.
     */
    public function recordWithdrawalWithPosting(array $input, int $userId): array
    {
        $accountId = (int)($input['savings_account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new InvalidArgumentException('A withdrawal must be linked to a savings account.');
        }
        $amount = (float)($input['amount'] ?? 0);

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $balance = $this->accountBalance($accountId);
            if ($amount > $balance) {
                throw new InvalidArgumentException(sprintf(
                    'Insufficient balance: account has Shs %.2f available, withdrawal requested is Shs %.2f.',
                    $balance, $amount
                ));
            }

            $input['transaction_type'] = 'withdrawal';
            $savingsId = $this->create($input);
            if ($savingsId === false) {
                throw new RuntimeException('Failed to record savings withdrawal.');
            }

            $result = $this->postWithdrawal($savingsId, $userId);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return array_merge(['id' => $savingsId], $result);
    }

    /**
     * Post an already-recorded savings withdrawal as a journal entry --
     * the mirror image of postDeposit(): Dr Members' Savings (liability
     * shrinks) / Cr cash/bank (cash leaves). Same GL accounts, same
     * period-resolution logic, same idempotency guarantee as postDeposit().
     */
    public function postWithdrawal(int $savingsId, int $userId): array
    {
        $saving = $this->find($savingsId);
        if (!$saving) {
            throw new InvalidArgumentException("Savings record id {$savingsId} does not exist.");
        }
        if (!empty($saving['journal_entry_id'])) {
            $je = $this->db->prepare("SELECT entry_number FROM journal_entries WHERE id = ?");
            $je->execute([$saving['journal_entry_id']]);
            return ['journal_entry_id' => (int)$saving['journal_entry_id'], 'entry_number' => $je->fetchColumn(), 'created' => false];
        }

        $debitAccountId = self::PAYMENT_ACCOUNTS[$saving['payment_method']] ?? 7;
        $cashAccount = (new AccountModel())->findActive($debitAccountId);
        if (!$cashAccount) {
            throw new InvalidArgumentException("The cash/bank account for payment method \"{$saving['payment_method']}\" does not exist or is inactive.");
        }
        $liabilityAccount = (new AccountModel())->findActive(self::SAVINGS_LIABILITY_ACCOUNT);
        if (!$liabilityAccount) {
            throw new InvalidArgumentException('The Members\' Savings liability account does not exist or is inactive.');
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
        $periodStmt->execute([$saving['transaction_date'], $saving['transaction_date']]);
        $period = $periodStmt->fetch();

        $amount = (float)$saving['debit'];

        $member = (new MemberModel())->find((int)$saving['member_id']);
        $memberLabel = $member ? trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) : ('member #' . $saving['member_id']);

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $service = new JournalService();
            $result = $service->post([
                'entry_date'             => $saving['transaction_date'],
                'description'            => "Savings withdrawal {$saving['receipt_number']} — {$memberLabel}",
                'source_module'          => 'savings',
                'source_reference_type'  => 'withdrawal',
                'source_reference_id'    => $savingsId,
                'financial_year_id'      => $period['financial_year_id'] ?? null,
                'accounting_period_id'   => $period['period_id'] ?? null,
                'created_by'             => $userId,
                'lines'                  => [
                    ['account_id' => $liabilityAccount['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Members\' Savings'],
                    ['account_id' => $cashAccount['id'], 'debit' => 0, 'credit' => $amount, 'description' => $saving['payment_method']],
                ],
            ]);

            $this->db->prepare("UPDATE `savings` SET journal_entry_id = ? WHERE id = ?")->execute([$result['id'], $savingsId]);

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

    // ----------------------------------------------------------------
    // Historical Balance Brought Forward (B/F)
    //
    // Represents genuine member savings that already existed before/
    // during a historical period and were not individually entered as
    // normal transactions. Deliberately subledger-only: this NEVER calls
    // postDeposit()/recordDepositWithPosting() and journal_entry_id
    // always stays NULL -- no cash/bank/mobile-money was received today,
    // so no journal is ever created for it (approved design; see
    // results/stage-bf-implementation-readiness-audit.md §4).
    // ----------------------------------------------------------------

    /**
     * Application-level duplicate guard: true if this account already
     * has a net-positive, unreversed Historical B/F on record. Uses the
     * NET effect (SUM(credit)-SUM(debit) of 'opening_balance' rows only)
     * rather than a row count, so that reversing an incorrect B/F (which
     * inserts an equal-and-opposite 'opening_balance' row, see
     * reverseBroughtForward() below) correctly nets back to zero and
     * allows a corrected replacement to be entered -- without needing a
     * separate "is this reversed" tracking column.
     */
    public function hasBroughtForward(int $accountId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM `savings`
             WHERE savings_account_id = ? AND transaction_type = 'opening_balance'"
        );
        $stmt->execute([$accountId]);
        return (float)$stmt->fetchColumn() > 0.0;
    }

    /**
     * Records a Historical Balance Brought Forward. $input must contain
     * member_id, savings_account_id, amount, transaction_date (the
     * historical as-of date -- never defaulted to today by this method;
     * the caller is responsible for requiring it explicitly) and notes
     * (the represented historical period, e.g. "1 May – 11 Sep 2026").
     *
     * payment_method is fixed to 'Other' -- a B/F was never received
     * through any real payment channel, so none of the real channels
     * (Cash/Mobile Money/Bank/Cheque) may be used merely to satisfy the
     * NOT NULL column (approved design, do not use a real channel here).
     * authorized_by is left NULL: this direct-entry design has no
     * genuine separate approval action, and the column must never be
     * fabricated with the creator's own id just to make it non-null.
     */
    public function createBroughtForward(array $input, int $userId): array
    {
        $accountId = (int)($input['savings_account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new InvalidArgumentException('A Balance Brought Forward must be linked to a savings account.');
        }
        $memberId = (int)($input['member_id'] ?? 0);
        if ($memberId <= 0) {
            throw new InvalidArgumentException('A Balance Brought Forward must be linked to a member.');
        }
        $amount = (float)($input['amount'] ?? 0);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
        $transactionDate = (string)($input['transaction_date'] ?? '');
        if ($transactionDate === '' || !DateTime::createFromFormat('Y-m-d', $transactionDate)) {
            throw new InvalidArgumentException('Please enter a valid historical effective (as-of) date.');
        }
        if ($transactionDate > date('Y-m-d')) {
            throw new InvalidArgumentException('The effective date cannot be in the future.');
        }
        $notes = trim((string)($input['notes'] ?? ''));
        if ($notes === '') {
            throw new InvalidArgumentException('Please describe the historical period this balance represents.');
        }

        if ($this->hasBroughtForward($accountId)) {
            throw new InvalidArgumentException(
                'This account already has a Historical Balance Brought Forward on record. ' .
                'Reverse the existing entry first if it was entered incorrectly.'
            );
        }

        $data = [
            'member_id'          => $memberId,
            'savings_account_id' => $accountId,
            'amount'             => $amount,
            'transaction_type'   => 'opening_balance',
            'payment_method'     => 'Other',
            'transaction_date'   => $transactionDate,
            'financial_year'     => (int)date('Y', strtotime($transactionDate)),
            'receipt_number'     => $this->generateReceiptNumber(),
            'description'        => 'Balance Brought Forward — Historical Savings',
            'notes'              => $notes,
            'recorded_by'        => $userId,
            'authorized_by'      => null,
            'reference_number'   => null,
        ];

        $savingsId = $this->create($data);
        if ($savingsId === false) {
            throw new RuntimeException('Failed to record Balance Brought Forward.');
        }

        return ['id' => $savingsId, 'receipt_number' => $data['receipt_number']];
    }

    /**
     * Corrects an incorrectly-entered B/F by reversing it — an
     * equal-and-opposite 'opening_balance' row (debit instead of
     * credit), never editing the original in place. Deliberately does
     * NOT call JournalService::reverse(): a genuine B/F row has no
     * journal_entry_id to reverse (this method explicitly refuses to
     * touch a row that somehow does have one, rather than silently
     * reversing a journal it was never meant to touch), preserving the
     * subledger-only accounting boundary end to end.
     *
     * SavingsModel::create()'s own branching always credits any
     * non-'withdrawal' transaction_type, so it cannot itself produce a
     * debit-direction 'opening_balance' row -- this method builds that
     * row directly (via the base Model::create(), bypassing that
     * branching) rather than changing create()'s shared logic, which
     * every deposit/withdrawal/adjustment caller also depends on.
     */
    public function reverseBroughtForward(int $savingsId, int $userId, string $reason): array
    {
        $row = $this->find($savingsId);
        if (!$row) {
            throw new InvalidArgumentException('Balance Brought Forward record not found.');
        }
        if ($row['transaction_type'] !== 'opening_balance' || (float)$row['debit'] > 0) {
            throw new InvalidArgumentException('This transaction is not an active Balance Brought Forward entry and cannot be reversed through this action.');
        }
        if (!empty($row['journal_entry_id'])) {
            throw new InvalidArgumentException('This entry is linked to a General Ledger journal and cannot be reversed through the Balance Brought Forward workflow.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to reverse a Balance Brought Forward entry.');
        }
        $amount = (float)$row['credit'];
        if ($amount <= 0) {
            throw new InvalidArgumentException('This entry has no positive balance to reverse.');
        }

        $accountId = (int)$row['savings_account_id'];

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $data = [
                'member_id'             => (int)$row['member_id'],
                'savings_account_id'    => $accountId,
                'transaction_type'      => 'opening_balance',
                'debit'                 => $amount,
                'credit'                => 0.00,
                'running_balance'       => $this->accountBalance($accountId) - $amount,
                'payment_method'        => 'Other',
                'cash_reference_number' => null,
                'transaction_date'      => date('Y-m-d'),
                'financial_year'        => (int)date('Y'),
                'receipt_number'        => $this->generateReceiptNumber(),
                'description'           => 'Reversal of Balance Brought Forward ' . $row['receipt_number'],
                'notes'                 => $reason,
                'recorded_by'           => $userId,
                'authorized_by'         => null,
                'reference_number'      => $row['receipt_number'],
            ];

            $newId = parent::create($data);
            if ($newId === false) {
                throw new RuntimeException('Failed to record the Balance Brought Forward reversal.');
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['id' => $newId, 'receipt_number' => $data['receipt_number']];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Account-scoped balance (mirrors MemberSavingsAccountModel::getAccountBalance() -- kept local to avoid a cross-model dependency for a one-line query). */
    private function accountBalance(int $accountId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)), 0) FROM `savings` WHERE savings_account_id = ?"
        );
        $stmt->execute([$accountId]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Override base find() to include the computed `amount` alias
     * (used by the edit form and internally for running-balance recalculation).
     */
    public function find(int $id): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT *, (COALESCE(credit,0)-COALESCE(debit,0)) AS amount
             FROM `savings` WHERE `id` = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    // ----------------------------------------------------------------
    // Paginated search + filters
    // ----------------------------------------------------------------
    public function search(
        string $term     = '',
        string $year     = '',
        string $month    = '',
        string $dateFrom = '',
        string $dateTo   = '',
        string $method   = '',
        int    $memberId = 0,
        int    $page     = 1,
        int    $perPage  = 15
    ): array {
        $where  = [];
        $params = [];

        if ($term !== '') {
            $like    = '%' . $term . '%';
            $where[] = '(s.receipt_number LIKE ? OR m.member_number LIKE ?
                         OR m.first_name LIKE ? OR m.last_name LIKE ? OR m.phone LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($year !== '') {
            $where[]  = 's.financial_year = ?';
            $params[] = $year;
        }
        if ($month !== '') {
            $where[]  = 'MONTH(s.transaction_date) = ?';
            $params[] = (int)$month;
        }
        if ($dateFrom !== '') {
            $where[]  = 's.transaction_date >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[]  = 's.transaction_date <= ?';
            $params[] = $dateTo;
        }
        if ($method !== '') {
            $where[]  = 's.payment_method = ?';
            $params[] = $method;
        }
        if ($memberId > 0) {
            $where[]  = 's.member_id = ?';
            $params[] = $memberId;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset   = ($page - 1) * $perPage;

        $from = "FROM `savings` s
                 JOIN `members` m ON m.id = s.member_id
                 LEFT JOIN `users` u ON u.id = s.recorded_by
                 {$whereSQL}";

        $countStmt = $this->db->prepare("SELECT COUNT(*) {$from}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $listStmt = $this->db->prepare(
            "SELECT s.*, (COALESCE(s.credit,0)-COALESCE(s.debit,0)) AS amount,
                    m.first_name, m.last_name, m.member_number,
                    u.full_name AS cashier_name
             {$from}
             ORDER BY s.transaction_date DESC, s.id DESC
             LIMIT ? OFFSET ?"
        );
        $i = 1;
        foreach ($params as $v) { $listStmt->bindValue($i++, $v); }
        $listStmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $listStmt->bindValue($i,   $offset,  PDO::PARAM_INT);
        $listStmt->execute();

        // Totals for filtered set
        $sumStmt = $this->db->prepare("SELECT COALESCE(SUM(COALESCE(s.credit,0)-COALESCE(s.debit,0)),0) {$from}");
        $sumStmt->execute($params);
        $filteredTotal = (float)$sumStmt->fetchColumn();

        return [
            'rows'          => $listStmt->fetchAll(),
            'total'         => $total,
            'pages'         => $total > 0 ? (int)ceil($total / $perPage) : 1,
            'filteredTotal' => $filteredTotal,
        ];
    }

    // ----------------------------------------------------------------
    // Create / Update / Delete
    //
    // The `savings` table stores a running ledger (debit/credit/
    // running_balance) rather than a flat `amount` column. These
    // overrides translate the app-level 'amount' field into that
    // ledger shape and keep running_balance consistent.
    //
    // Stage 5B: when `savings_account_id` is present in $data, running
    // balance and debit/credit direction are scoped to that ACCOUNT
    // (never the member's other accounts) and transaction_type
    // determines direction (deposit vs withdrawal). When it is absent
    // -- every historical row, and any caller that predates Stage 5B --
    // behavior is byte-for-byte identical to before Stage 5B: always
    // treated as a deposit, debit left NULL, balance computed across
    // all of the member's (account-less) rows. The two paths never
    // touch the same rows, since recalcRunningBalances() below is
    // additionally scoped to `savings_account_id IS NULL`.
    // ----------------------------------------------------------------
    public function create(array $data): int|false
    {
        $amount    = (float)($data['amount'] ?? 0);
        $accountId = $data['savings_account_id'] ?? null;
        unset($data['amount']);

        if ($accountId !== null) {
            $type = $data['transaction_type'] ?? 'deposit';
            $data['transaction_type'] = $type;
            if ($type === 'withdrawal') {
                $data['debit']           = $amount;
                $data['credit']          = 0.00;
                $data['running_balance'] = $this->accountBalance((int)$accountId) - $amount;
            } else {
                $data['debit']           = 0.00;
                $data['credit']          = $amount;
                $data['running_balance'] = $this->accountBalance((int)$accountId) + $amount;
            }
        } else {
            $data['transaction_type'] = $data['transaction_type'] ?? 'deposit';
            $data['debit']            = null;
            $data['credit']           = $amount;
            $data['running_balance']  = $this->memberBalance((int)$data['member_id']) + $amount;
        }

        // Auto-populate a human-readable description when the caller
        // didn't already supply one (deposits/withdrawals recorded through
        // the normal UI never have -- `description` sat permanently NULL
        // for every ordinary transaction, confirmed against production;
        // only MemberAccountAdjustmentModel's own direct INSERT already
        // populates it, for adjustments specifically, with the reason
        // text). This never overwrites an explicitly-provided value.
        if (empty($data['description']) && !empty($data['payment_method'])) {
            $data['description'] = ucfirst($data['transaction_type'] ?? 'deposit') . ' via ' . $data['payment_method'];
        }

        // Cash Reference (CHS-000001) is always server-computed here,
        // never taken from caller input -- this unconditionally overwrites
        // any 'cash_reference_number' key the caller might already have
        // set (none currently do), so there is no path by which a caller-
        // or client-supplied value could ever reach the database.
        $data['cash_reference_number'] = ($data['payment_method'] ?? null) === 'Cash'
            ? $this->nextCashReference('CHS')
            : null;

        return parent::create($data);
    }

    /**
     * Stage 13-F3 (13E-FIN-01): once a savings row has been posted to the
     * General Ledger (journal_entry_id set by postDeposit()/postWithdrawal()),
     * its financial facts are historical evidence and must never be
     * silently rewritten -- the previous behavior let an edit change the
     * amount, date, member, account, or payment method of an already-posted
     * transaction while the journal_entries/journal_lines rows it produced
     * sat completely untouched, permanently desyncing the ledger from the
     * application record with zero trace. This list is deliberately the
     * set of fields that determine the transaction's accounting meaning
     * (exactly what postDeposit()/postWithdrawal() read to build the
     * journal lines, plus the identifiers that tie the row to its member/
     * account/journal) -- NOT purely descriptive fields like `notes` or
     * `reference_number`, which remain editable on a posted row per the
     * Stage 13-F3 spec's explicit instruction not to blindly lock
     * unrelated descriptive fields.
     */
    private const SAVINGS_FINANCIAL_FIELDS = [
        'amount', 'debit', 'credit', 'member_id', 'savings_account_id',
        'transaction_type', 'payment_method', 'transaction_date',
        'financial_year', 'cash_reference_number', 'journal_entry_id',
        'running_balance', 'recorded_by',
    ];

    public function update(int $id, array $data): bool
    {
        $existingRow = $this->find($id);
        if ($existingRow && !empty($existingRow['journal_entry_id'])) {
            $blocked = array_intersect(self::SAVINGS_FINANCIAL_FIELDS, array_keys($data));
            if ($blocked) {
                throw new InvalidArgumentException(
                    "Savings record {$existingRow['receipt_number']} has already been posted to the General Ledger " .
                    "(journal entry linked) and its financial details cannot be edited. " .
                    "To correct a posted transaction, reverse it through the Controlled Corrections workflow " .
                    "and record the correction as a new entry instead."
                );
            }
        }

        // Stage B/F: a Historical Balance Brought Forward never carries a
        // journal_entry_id (subledger-only, by design -- see
        // createBroughtForward() above), so the guard above never applies
        // to it. It still must never be edited in place: the same
        // append-only philosophy applies, just enforced independently of
        // journal_entry_id here. Correction is via reverseBroughtForward().
        if ($existingRow && $existingRow['transaction_type'] === 'opening_balance') {
            $blocked = array_intersect(self::SAVINGS_FINANCIAL_FIELDS, array_keys($data));
            if ($blocked) {
                throw new InvalidArgumentException(
                    "Balance Brought Forward record {$existingRow['receipt_number']} cannot be edited. " .
                    "To correct it, reverse it and record a fresh, corrected Balance Brought Forward instead."
                );
            }
        }

        $hasAmount = array_key_exists('amount', $data);
        $amount    = $hasAmount ? (float)$data['amount'] : null;
        unset($data['amount']);

        $existing  = $this->find($id);
        $accountId = $existing['savings_account_id'] ?? null;

        if ($hasAmount) {
            if ($accountId !== null) {
                $type = $data['transaction_type'] ?? $existing['transaction_type'] ?? 'deposit';
                if ($type === 'withdrawal') {
                    $data['debit']  = $amount;
                    $data['credit'] = 0.00;
                } else {
                    $data['debit']  = 0.00;
                    $data['credit'] = $amount;
                }
            } else {
                $data['debit']  = null;
                $data['credit'] = $amount;
            }
        }

        $ok = parent::update($id, $data);

        if ($ok) {
            if ($accountId !== null) {
                $this->recalcAccountRunningBalances((int)$accountId);
            } else {
                $memberId = $data['member_id'] ?? ($existing['member_id'] ?? null);
                if ($memberId !== null) {
                    $this->recalcRunningBalances((int)$memberId);
                }
            }
        }

        return $ok;
    }

    /**
     * Stage 9: reverses this row's posted journal entry (if any) before
     * deleting it, so a deleted savings row can never leave an orphaned
     * journal behind. Atomic; running-balance recalculation is unchanged.
     *
     * Stage 13-F3 (13E-FIN-01): a POSTED savings row (journal_entry_id set)
     * is historical financial evidence -- once it has entered the GL it
     * must not be destroyed via this generic delete path any more, even
     * though the previous Stage 9 behavior of reversing-then-deleting kept
     * the ledger balanced. The reversal keeps the *balance* correct but the
     * deletion itself still erases the one application record an auditor
     * would use to see what the original deposit/withdrawal actually was
     * (journal_entries.source_reference_id is left pointing at a row that
     * no longer exists). $allowPostedReversal is a narrow, code-only escape
     * hatch -- never settable from request input, never reachable through
     * SavingsController -- for the ONE pre-existing, already-audited
     * internal caller that legitimately needs this exact behavior:
     * WithdrawalModel::reverseWithdrawal()'s "Delete-and-Reverse" (Stage 26)
     * pattern, which deletes the savings row it itself created to *undo an
     * entire withdrawal transaction*, not to edit/erase a standalone
     * savings deposit. That is a different module's own reversal mechanism
     * (out of this stage's scope per its findings, which are savings/loans
     * only) and is left byte-for-byte functionally unchanged, still fully
     * gated by AUTH-D-02 below via JournalService::reverse(). Every other
     * caller -- SavingsController::delete()/bulkDelete(), and any future or
     * direct model invocation -- gets the new, stricter behavior: deleting
     * a posted savings row is blocked outright, with zero mutation. The
     * correction path for a posted savings row is the existing Controlled
     * Corrections / reversal workflow (JournalService::reverse() directly
     * on the journal entry), not deletion of the source record.
     */
    public function delete(int $id, int $userId = 0, bool $allowPostedReversal = false): bool
    {
        $row = $this->find($id);
        if (!$row) {
            return false;
        }

        if (!empty($row['journal_entry_id']) && !$allowPostedReversal) {
            throw new InvalidArgumentException(
                "Savings record {$row['receipt_number']} has already been posted to the General Ledger " .
                "(journal entry linked) and cannot be deleted. " .
                "To correct a posted transaction, reverse it through the Controlled Corrections workflow instead."
            );
        }

        // Stage B/F: same append-only protection as above, independent of
        // journal_entry_id (a B/F row never has one) -- deleting it would
        // erase the historical record with no reason/trace, contrary to
        // the approved correction philosophy. Use reverseBroughtForward().
        if ($row['transaction_type'] === 'opening_balance') {
            throw new InvalidArgumentException(
                "Balance Brought Forward record {$row['receipt_number']} cannot be deleted. " .
                "To correct it, reverse it and record a fresh, corrected Balance Brought Forward instead."
            );
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            if (!empty($row['journal_entry_id'])) {
                (new JournalService())->reverse((int)$row['journal_entry_id'], $userId, 'Savings record deleted');
            }
            $ok = parent::delete($id);

            if ($ok) {
                if (!empty($row['savings_account_id'])) {
                    $this->recalcAccountRunningBalances((int)$row['savings_account_id']);
                } else {
                    $this->recalcRunningBalances((int)$row['member_id']);
                }
            }

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

    /**
     * Recompute running_balance for every LEGACY (account-less) savings
     * row of a member, in chronological order. Explicitly excludes any
     * account-linked row so an edit/delete of an old historical-style
     * row can never disturb a Stage-5B account's own running_balance
     * sequence, even if the two happen to share a member_id.
     */
    private function recalcRunningBalances(int $memberId): void
    {
        $stmt = $this->db->prepare(
            "SELECT id, debit, credit FROM `savings`
             WHERE member_id = ? AND savings_account_id IS NULL
             ORDER BY transaction_date ASC, id ASC"
        );
        $stmt->execute([$memberId]);
        $rows = $stmt->fetchAll();

        $balance = 0.0;
        $upd = $this->db->prepare("UPDATE `savings` SET running_balance = ? WHERE id = ?");
        foreach ($rows as $row) {
            $balance += (float)($row['credit'] ?? 0) - (float)($row['debit'] ?? 0);
            $upd->execute([$balance, $row['id']]);
        }
    }

    /** Stage 5B: same recompute, scoped to one savings account instead of a member. */
    private function recalcAccountRunningBalances(int $accountId): void
    {
        $stmt = $this->db->prepare(
            "SELECT id, debit, credit FROM `savings`
             WHERE savings_account_id = ?
             ORDER BY transaction_date ASC, id ASC"
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll();

        $balance = 0.0;
        $upd = $this->db->prepare("UPDATE `savings` SET running_balance = ? WHERE id = ?");
        foreach ($rows as $row) {
            $balance += (float)($row['credit'] ?? 0) - (float)($row['debit'] ?? 0);
            $upd->execute([$balance, $row['id']]);
        }
    }

    // ----------------------------------------------------------------
    // Uniqueness
    // ----------------------------------------------------------------
    public function receiptExists(string $receipt, ?int $excludeId = null): bool
    {
        $sql    = "SELECT COUNT(*) FROM `savings` WHERE `receipt_number` = ?";
        $params = [$receipt];
        if ($excludeId !== null) { $sql .= ' AND id != ?'; $params[] = $excludeId; }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    // ----------------------------------------------------------------
    // Financial years list (for filter dropdown)
    // ----------------------------------------------------------------
    public function getFinancialYears(): array
    {
        try {
            return $this->db->query(
                "SELECT DISTINCT financial_year FROM `savings` ORDER BY financial_year DESC"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) { return []; }
    }

    // ----------------------------------------------------------------
    // Activity log
    // ----------------------------------------------------------------
    public function log(int $userId, string $action, string $desc = ''): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `activity_logs`(`user_id`,`action`,`description`,`ip_address`)
             VALUES (?,?,?,?)"
        );
        $stmt->execute([$userId, $action, $desc, $_SERVER['REMOTE_ADDR'] ?? null]);
    }
}
