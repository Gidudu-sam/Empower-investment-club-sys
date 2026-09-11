<?php
/**
 * WithdrawalModel — Annual Withdrawals & Share Retention
 */
class WithdrawalModel extends Model
{
    protected string $table      = 'withdrawals';
    protected string $primaryKey = 'id';

    // ── Number generation  WDL-000001 ────────────────────────
    public function generateNumber(): string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT MAX(CAST(SUBSTRING(withdrawal_number,5) AS UNSIGNED)) AS mx
                 FROM `withdrawals`"
            );
            $stmt->execute();
            $next = (int)($stmt->fetch()['mx'] ?? 0) + 1;
            return 'WDL-' . str_pad($next, 6, '0', STR_PAD_LEFT);
        } catch (PDOException $e) { return 'WDL-000001'; }
    }

    // ── Check if member already made the ANNUAL COMPULSORY withdrawal
    //    this year. Type-scoped (Stage 17 Part E) -- a voluntary
    //    withdrawal, which may legitimately repeat any number of times in
    //    the same financial year, must never count toward this check.
    public function hasWithdrawnThisYear(int $memberId, int $year, string $withdrawalType = 'annual_compulsory'): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM `withdrawals`
                 WHERE member_id=? AND financial_year=? AND withdrawal_type=?"
            );
            $stmt->execute([$memberId, $year, $withdrawalType]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $e) { return false; }
    }

    // ── Single record with member + cashier + account + journal ──
    public function findWithDetails(int $id): array|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT w.*,
                        m.first_name, m.last_name, m.member_number, m.phone AS member_phone,
                        u.full_name AS cashier_name,
                        a.account_number, a.account_type,
                        je.entry_number
                 FROM `withdrawals` w
                 JOIN `members` m ON m.id = w.member_id
                 LEFT JOIN `users` u ON u.id = w.processed_by
                 LEFT JOIN `member_savings_accounts` a ON a.id = w.savings_account_id
                 LEFT JOIN `journal_entries` je ON je.id = w.journal_entry_id
                 WHERE w.id = ? LIMIT 1"
            );
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException $e) { return false; }
    }

    // ================================================================
    // WITHDRAWAL TRANSACTION ENGINE (Stage 17 Part E)
    //
    // Two distinct, atomic, policy-driven flows. Neither hard-codes a
    // percentage anywhere -- both resolve WithdrawalPolicyModel::
    // getActivePolicy() fresh, then use its pure calculation helpers.
    // Both lock the specific `member_savings_accounts` row FOR UPDATE
    // before re-reading the balance, so two concurrent requests against
    // the same account are serialized (mirrors the exact same technique
    // already used for `loans`/`loan_penalties` rows in Stage 17 Part C).
    // ================================================================

    /** payment_method -> debit-side cash/bank GL account id (same mapping used everywhere else in this codebase) */
    private const PAYMENT_ACCOUNTS = [
        'Cash'              => 7,  // 1110 Cash at Hand
        'MTN Mobile Money'  => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Airtel Money'      => 8,  // 1120 Mobile Money / Float (MTN and Airtel combined)
        'Bank Transfer'     => 10, // 1140 Bank Accounts
        'Cheque'            => 10, // 1140 Bank Accounts
        'Other'             => 7,  // fallback: Cash at Hand
    ];

    private const SAVINGS_LIABILITY_ACCOUNT = 17; // 2020 Members' Savings
    private const SHARE_CAPITAL_ACCOUNT     = 24; // 3010 Shares (Share Capital)

    /**
     * Process an annual compulsory-savings withdrawal. Under the confirmed
     * Model A rule, the member's ENTIRE qualifying compulsory balance is
     * consumed in one economic event: the requested cash amount, plus
     * whatever is not withdrawn converting fully to Share Capital. The
     * member may request any amount from just above zero up to the
     * policy's configured maximum -- the retained/share amount is always
     * (qualifying_balance - requested_amount), never a fixed percentage of
     * the balance independent of what was actually requested.
     *
     * @throws InvalidArgumentException on any validation failure -- no
     *         partial state is ever left behind (single DB transaction).
     */
    public function processAnnualCompulsory(array $data, int $userId): int
    {
        $memberId        = (int)($data['member_id'] ?? 0);
        $requestedAmount = round((float)($data['requested_amount'] ?? 0), 2);
        $paymentMethod   = $data['payment_method'] ?? 'Cash';
        $withdrawalDate  = $data['withdrawal_date'] ?? date('Y-m-d');

        if ($memberId <= 0) {
            throw new InvalidArgumentException('A member must be selected.');
        }
        if ($requestedAmount <= 0) {
            throw new InvalidArgumentException('Withdrawal amount must be greater than zero.');
        }
        if (!isset(self::PAYMENT_ACCOUNTS[$paymentMethod])) {
            throw new InvalidArgumentException("Invalid payment method \"{$paymentMethod}\".");
        }

        $member = (new MemberModel())->find($memberId);
        if (!$member) {
            throw new InvalidArgumentException('Member not found.');
        }
        if ($member['status'] !== 'active') {
            throw new InvalidArgumentException('This member is not active -- withdrawals cannot be processed.');
        }

        $accountModel = new MemberSavingsAccountModel();
        $compulsoryAccount = $this->findMemberAccountByType($accountModel, $memberId, 'compulsory');
        if (!$compulsoryAccount) {
            throw new InvalidArgumentException('This member has no compulsory savings account.');
        }
        if ($compulsoryAccount['status'] !== 'active') {
            throw new InvalidArgumentException('This member\'s compulsory savings account is not active.');
        }
        $qualification = $accountModel->checkCompulsoryQualification((int)$compulsoryAccount['id']);
        if (!$qualification['qualified']) {
            throw new InvalidArgumentException('This member\'s compulsory savings account has not yet met the qualification threshold -- no qualifying compulsory savings are available to withdraw.');
        }

        $financialYear = (int)date('Y', strtotime($withdrawalDate));

        $policyModel = new WithdrawalPolicyModel();
        $policy = $policyModel->getActivePolicy('compulsory', $withdrawalDate);
        if (!$policy || empty($policy['withdrawal_enabled'])) {
            throw new InvalidArgumentException('Compulsory savings withdrawal is not currently permitted (no active, enabled policy).');
        }
        if ($policy['frequency'] === 'once_per_financial_year' && $this->hasWithdrawnThisYear($memberId, $financialYear, 'annual_compulsory')) {
            throw new InvalidArgumentException("This member has already made the annual compulsory withdrawal for {$financialYear}.");
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            // Lock the account row to serialize any concurrent withdrawal
            // attempt against the same compulsory account before re-reading
            // its balance -- the authoritative figure used for validation
            // and the actual posting is always read AFTER this lock, never
            // trusted from an earlier, unlocked read.
            $this->db->prepare("SELECT id FROM `member_savings_accounts` WHERE id = ? FOR UPDATE")
                ->execute([$compulsoryAccount['id']]);

            $qualifyingBalance = $accountModel->getAccountBalance((int)$compulsoryAccount['id']);

            $maxWithdrawal = $policyModel->calculateMaximumWithdrawal($qualifyingBalance, $policy);
            if ($requestedAmount > $maxWithdrawal + 0.01) {
                throw new InvalidArgumentException(sprintf(
                    'Requested withdrawal of Shs %s exceeds the maximum allowed of Shs %s (%.2f%% of the qualifying compulsory balance of Shs %s).',
                    number_format($requestedAmount, 2), number_format($maxWithdrawal, 2),
                    (float)$policy['maximum_withdrawal_percent'], number_format($qualifyingBalance, 2)
                ));
            }

            $conversion     = $policyModel->calculateShareConversion($qualifyingBalance, $requestedAmount, $policy);
            $retainedAmount = $conversion['share_conversion'];
            // consumedAmount = qualifying_balance - remains_in_savings. For
            // today's approved policy (max+share=100), remains_in_savings is
            // always 0, so consumedAmount = qualifying_balance exactly --
            // the whole account is consumed, ending balance = 0, per Model A.
            $consumedAmount = round($requestedAmount + $retainedAmount, 2);

            $cashAccount = (new AccountModel())->findActive(self::PAYMENT_ACCOUNTS[$paymentMethod]);
            if (!$cashAccount) {
                throw new InvalidArgumentException("The cash/bank account for payment method \"{$paymentMethod}\" does not exist or is inactive.");
            }
            $liabilityAccount = (new AccountModel())->findActive(self::SAVINGS_LIABILITY_ACCOUNT);
            if (!$liabilityAccount) {
                throw new InvalidArgumentException('The Members\' Savings liability account does not exist or is inactive.');
            }
            $sharesAccount = null;
            if ($retainedAmount > 0) {
                $sharesAccount = (new AccountModel())->findActive(self::SHARE_CAPITAL_ACCOUNT);
                if (!$sharesAccount) {
                    throw new InvalidArgumentException('The Shares (Share Capital) account does not exist or is inactive.');
                }
            }

            // Reduce the savings ledger: ONE debit row for the full consumed
            // amount (cash + shares combined) -- not two separate reductions
            // (§22 "No Double Reduction"). Reuses SavingsModel::create()'s
            // existing account-scoped debit/running_balance bookkeeping.
            $savingsModel = new SavingsModel();
            $savingsId = $savingsModel->create([
                'member_id'          => $memberId,
                'savings_account_id' => $compulsoryAccount['id'],
                'transaction_type'   => 'withdrawal',
                'amount'             => $consumedAmount,
                'payment_method'     => $paymentMethod,
                'transaction_date'   => $withdrawalDate,
                'receipt_number'     => $savingsModel->generateReceiptNumber(),
                'recorded_by'        => $userId,
            ]);
            if ($savingsId === false) {
                throw new RuntimeException('Failed to record the compulsory savings ledger reduction.');
            }

            $withdrawalNumber = $this->generateNumber();
            $withdrawalId = $this->create([
                'withdrawal_number'       => $withdrawalNumber,
                'member_id'               => $memberId,
                'savings_account_id'      => $compulsoryAccount['id'],
                'financial_year'          => $financialYear,
                'withdrawal_type'         => 'annual_compulsory',
                'total_available_savings' => $qualifyingBalance,
                'withdrawal_percentage'   => $qualifyingBalance > 0 ? round($requestedAmount / $qualifyingBalance * 100, 2) : 0,
                'retained_percentage'     => $qualifyingBalance > 0 ? round($retainedAmount / $qualifyingBalance * 100, 2) : 0,
                'withdrawal_amount'       => $requestedAmount,
                'retained_amount'         => $retainedAmount,
                'withdrawal_date'         => $withdrawalDate,
                'payment_method'          => $paymentMethod,
                'reference_number'        => $data['reference_number'] ?? null,
                'remarks'                 => $data['remarks'] ?? null,
                'processed_by'            => $userId,
            ]);
            if ($withdrawalId === false) {
                throw new RuntimeException('Failed to record the withdrawal.');
            }

            $lines = [
                ['account_id' => $liabilityAccount['id'], 'debit' => $consumedAmount, 'credit' => 0, 'description' => 'Members\' Savings (compulsory)'],
            ];
            if ($requestedAmount > 0) {
                $lines[] = ['account_id' => $cashAccount['id'], 'debit' => 0, 'credit' => $requestedAmount, 'description' => $paymentMethod];
            }
            if ($retainedAmount > 0) {
                $lines[] = ['account_id' => $sharesAccount['id'], 'debit' => 0, 'credit' => $retainedAmount, 'description' => 'Share Capital conversion'];
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
            $periodStmt->execute([$withdrawalDate, $withdrawalDate]);
            $period = $periodStmt->fetch();

            $memberLabel = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));

            $service = new JournalService();
            $result = $service->post([
                'entry_date'            => $withdrawalDate,
                'description'           => "Annual compulsory withdrawal {$withdrawalNumber} — {$memberLabel}",
                'source_module'         => 'withdrawals',
                'source_reference_type' => 'annual_compulsory',
                'source_reference_id'   => $withdrawalId,
                'financial_year_id'     => $period['financial_year_id'] ?? null,
                'accounting_period_id'  => $period['period_id'] ?? null,
                'created_by'            => $userId,
                'lines'                 => $lines,
            ]);

            $this->update($withdrawalId, ['journal_entry_id' => $result['id']]);
            $this->db->prepare("UPDATE `savings` SET journal_entry_id = ? WHERE id = ?")->execute([$result['id'], $savingsId]);

            // Stage 2 (Shares Module): mirror the retained portion into the
            // share ledger, inside this SAME transaction -- not a second
            // financial posting, just an ownership/history record pointing
            // back at the journal entry already posted above. Zero-retained
            // withdrawals (retained_amount == 0, e.g. a member who requests
            // their full ceiling in cash) deliberately create no mirror row.
            if ($retainedAmount > 0) {
                (new ShareModel())->mirrorRetainedWithdrawal([
                    'member_id'            => $memberId,
                    'transaction_date'     => $withdrawalDate,
                    'amount'               => $retainedAmount,
                    'payment_method'       => $paymentMethod,
                    'source_reference_id'  => $withdrawalId,
                    'journal_entry_id'     => $result['id'],
                    'processed_by'         => $userId,
                    'reference_number'     => $withdrawalNumber,
                ]);
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
            return $withdrawalId;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Process a voluntary-savings withdrawal. No automatic share
     * conversion -- reduces the voluntary account by exactly the requested
     * amount, leaving the remainder intact. Reuses
     * SavingsModel::recordWithdrawalWithPosting() AS-IS for the ledger
     * reduction and the (already-correct, already-existing) Dr Members'
     * Savings / Cr Cash-Bank journal -- no new posting logic was written
     * for this path.
     */
    public function processVoluntary(array $data, int $userId): int
    {
        $memberId        = (int)($data['member_id'] ?? 0);
        $requestedAmount = round((float)($data['requested_amount'] ?? 0), 2);
        $paymentMethod   = $data['payment_method'] ?? 'Cash';
        $withdrawalDate  = $data['withdrawal_date'] ?? date('Y-m-d');

        if ($memberId <= 0) {
            throw new InvalidArgumentException('A member must be selected.');
        }
        if ($requestedAmount <= 0) {
            throw new InvalidArgumentException('Withdrawal amount must be greater than zero.');
        }

        $member = (new MemberModel())->find($memberId);
        if (!$member) {
            throw new InvalidArgumentException('Member not found.');
        }
        if ($member['status'] !== 'active') {
            throw new InvalidArgumentException('This member is not active -- withdrawals cannot be processed.');
        }

        $accountModel = new MemberSavingsAccountModel();
        $voluntaryAccount = $this->findMemberAccountByType($accountModel, $memberId, 'voluntary');
        if (!$voluntaryAccount) {
            throw new InvalidArgumentException('This member has no voluntary savings account.');
        }
        if ($voluntaryAccount['status'] !== 'active') {
            throw new InvalidArgumentException('This member\'s voluntary savings account is not active.');
        }

        $financialYear = (int)date('Y', strtotime($withdrawalDate));

        $policyModel = new WithdrawalPolicyModel();
        $policy = $policyModel->getActivePolicy('voluntary', $withdrawalDate);
        if (!$policy || empty($policy['withdrawal_enabled'])) {
            throw new InvalidArgumentException('Voluntary savings withdrawal is not currently permitted (no active, enabled policy).');
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare("SELECT id FROM `member_savings_accounts` WHERE id = ? FOR UPDATE")
                ->execute([$voluntaryAccount['id']]);

            $balance = $accountModel->getAccountBalance((int)$voluntaryAccount['id']);
            $maxWithdrawal = $policyModel->calculateMaximumWithdrawal($balance, $policy);
            if ($requestedAmount > $maxWithdrawal + 0.01) {
                throw new InvalidArgumentException(sprintf(
                    'Requested withdrawal of Shs %s exceeds the maximum allowed of Shs %s (%.2f%% of the voluntary balance of Shs %s).',
                    number_format($requestedAmount, 2), number_format($maxWithdrawal, 2),
                    (float)$policy['maximum_withdrawal_percent'], number_format($balance, 2)
                ));
            }

            // recordWithdrawalWithPosting() independently re-validates
            // amount <= balance as its own money-safety invariant, and
            // joins this already-open transaction rather than starting a
            // competing one (Model::inTransaction() check inside it).
            $savingsModel = new SavingsModel();
            $posted = $savingsModel->recordWithdrawalWithPosting([
                'member_id'          => $memberId,
                'savings_account_id' => $voluntaryAccount['id'],
                'amount'             => $requestedAmount,
                'payment_method'     => $paymentMethod,
                'transaction_date'   => $withdrawalDate,
                'receipt_number'     => $savingsModel->generateReceiptNumber(),
                'recorded_by'        => $userId,
            ], $userId);

            $withdrawalNumber = $this->generateNumber();
            $withdrawalId = $this->create([
                'withdrawal_number'       => $withdrawalNumber,
                'member_id'               => $memberId,
                'savings_account_id'      => $voluntaryAccount['id'],
                'financial_year'          => $financialYear,
                'withdrawal_type'         => 'voluntary',
                'total_available_savings' => $balance,
                'withdrawal_percentage'   => $balance > 0 ? round($requestedAmount / $balance * 100, 2) : 0,
                'retained_percentage'     => 0,
                'withdrawal_amount'       => $requestedAmount,
                'retained_amount'         => 0,
                'withdrawal_date'         => $withdrawalDate,
                'payment_method'          => $paymentMethod,
                'reference_number'        => $data['reference_number'] ?? null,
                'remarks'                 => $data['remarks'] ?? null,
                'processed_by'            => $userId,
                'journal_entry_id'        => $posted['journal_entry_id'],
            ]);
            if ($withdrawalId === false) {
                throw new RuntimeException('Failed to record the withdrawal.');
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
            return $withdrawalId;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Delete-and-Reverse (§26): reverses the linked journal entry (via the
     * underlying savings row, reusing SavingsModel::delete() so the journal
     * is reversed exactly once regardless of withdrawal type) and removes
     * the withdrawal record. Mirrors the identical pattern already
     * established for Loans/Savings/Repayments -- no new reversal mechanism.
     */
    public function reverseWithdrawal(int $withdrawalId, int $userId): bool
    {
        $withdrawal = $this->find($withdrawalId);
        if (!$withdrawal) {
            throw new InvalidArgumentException("Withdrawal id {$withdrawalId} does not exist.");
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            if (!empty($withdrawal['journal_entry_id'])) {
                $savingsRow = $this->db->prepare("SELECT id FROM `savings` WHERE journal_entry_id = ? LIMIT 1");
                $savingsRow->execute([$withdrawal['journal_entry_id']]);
                $savingsId = $savingsRow->fetchColumn();

                if ($savingsId) {
                    // Reverses the journal and deletes the savings row,
                    // recalculating the account's running balance --
                    // restores the pre-withdrawal balance exactly, whether
                    // the original debit was the combined cash+shares
                    // amount (annual) or just the cash amount (voluntary).
                    // Stage 13-F3: SavingsModel::delete() now refuses to
                    // delete a posted (journal-linked) row by default (see
                    // its own docblock) -- this withdrawal-reversal flow is
                    // the one pre-existing, already-audited caller that
                    // must keep working exactly as before, so it explicitly
                    // opts in via $allowPostedReversal=true. This is a
                    // hardcoded, code-only flag; nothing from request input
                    // reaches it, and it does not weaken AUTH-D-02 -- the
                    // underlying JournalService::reverse() self-reversal
                    // check still runs unconditionally.
                    (new SavingsModel())->delete((int)$savingsId, $userId, true);
                } else {
                    (new JournalService())->reverse((int)$withdrawal['journal_entry_id'], $userId, 'Withdrawal reversed (savings row not found)');
                }
            }

            $stmt = $this->db->prepare("DELETE FROM `withdrawals` WHERE id = ?");
            $stmt->execute([$withdrawalId]);
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

    private function findMemberAccountByType(MemberSavingsAccountModel $accountModel, int $memberId, string $accountType): array|null
    {
        foreach ($accountModel->getMemberAccounts($memberId) as $a) {
            if ($a['account_type'] === $accountType) {
                return $a;
            }
        }
        return null;
    }

    // ── Member withdrawal history ─────────────────────────────
    public function memberHistory(int $memberId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT w.*, u.full_name AS cashier_name
                 FROM `withdrawals` w
                 LEFT JOIN `users` u ON u.id = w.processed_by
                 WHERE w.member_id = ?
                 ORDER BY w.financial_year DESC"
            );
            $stmt->execute([$memberId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    /** Total retained shares for a member across all years */
    public function memberTotalRetained(int $memberId): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(retained_amount),0)
                 FROM `withdrawals` WHERE member_id=?"
            );
            $stmt->execute([$memberId]);
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    /** Total cash withdrawn by a member */
    public function memberTotalWithdrawn(int $memberId): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(withdrawal_amount),0)
                 FROM `withdrawals` WHERE member_id=?"
            );
            $stmt->execute([$memberId]);
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    // ── Dashboard stats ──────────────────────────────────────
    public function totalWithdrawals(): float
    {
        try {
            return (float)$this->db->query(
                "SELECT COALESCE(SUM(withdrawal_amount),0) FROM `withdrawals`"
            )->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    public function todayTotal(): float
    {
        try {
            return (float)$this->db->query(
                "SELECT COALESCE(SUM(withdrawal_amount),0) FROM `withdrawals` WHERE `withdrawal_date` = CURDATE()"
            )->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    /**
     * Total share capital -- this is the actual source the Dashboard's
     * "Total Shares" card and the Withdrawals page's "Total Retained
     * Shares" card both read. Was summing only `withdrawals.retained_amount`,
     * the same gap Stage 4-A already fixed for the Reports > Shares page
     * (ReportModel::getShareReport()) and the Dashboard's OWN separate
     * ReportModel::getDashboardStats()['total_shares'] -- but this is the
     * method those two dashboard cards actually call, which neither of
     * those earlier fixes touched. Reuses the same proven
     * ShareModel::totalShareCapital() merge (withdrawals + share_transactions).
     */
    public function totalRetained(): float
    {
        try {
            return (new ShareModel())->totalShareCapital();
        } catch (PDOException $e) { return 0; }
    }

    public function annualWithdrawals(int $year = 0): float
    {
        $year = $year ?: (int)date('Y');
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(withdrawal_amount),0)
                 FROM `withdrawals` WHERE financial_year=?"
            );
            $stmt->execute([$year]);
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }

    /** Member with the highest retained shares across all years */
    public function topShareholder(): array|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT m.id, m.first_name, m.last_name, m.member_number,
                        SUM(w.retained_amount) AS total_retained
                 FROM `withdrawals` w
                 JOIN `members` m ON m.id = w.member_id
                 WHERE w.retained_amount > 0
                 GROUP BY m.id, m.first_name, m.last_name, m.member_number
                 ORDER BY total_retained DESC
                 LIMIT 1"
            );
            $stmt->execute();
            return $stmt->fetch();
        } catch (PDOException $e) { return false; }
    }

    public function recent(int $limit = 5): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT w.*, m.first_name, m.last_name, m.member_number
                 FROM `withdrawals` w
                 JOIN `members` m ON m.id = w.member_id
                 ORDER BY w.created_at DESC LIMIT ?"
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    // ── Paginated search + filters ───────────────────────────
    public function search(
        string $term   = '',
        int    $year   = 0,
        string $method = '',
        int    $page   = 1,
        int    $perPage = 15
    ): array {
        $where = []; $params = [];

        if ($term !== '') {
            $like    = '%'.$term.'%';
            $where[] = '(w.withdrawal_number LIKE ? OR m.member_number LIKE ?
                         OR m.first_name LIKE ? OR m.last_name LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        if ($year > 0)    { $where[] = 'w.financial_year=?';    $params[] = $year; }
        if ($method !== '') { $where[] = 'w.payment_method=?';  $params[] = $method; }

        $whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';
        $offset   = ($page-1) * $perPage;

        $from = "FROM `withdrawals` w
                 JOIN `members` m ON m.id = w.member_id
                 LEFT JOIN `users` u ON u.id = w.processed_by
                 {$whereSQL}";

        $countStmt = $this->db->prepare("SELECT COUNT(*) {$from}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sumStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(w.withdrawal_amount),0) {$from}"
        );
        $sumStmt->execute($params);
        $totalAmt = (float)$sumStmt->fetchColumn();

        $listStmt = $this->db->prepare(
            "SELECT w.*, m.first_name, m.last_name, m.member_number,
                    u.full_name AS cashier_name
             {$from}
             ORDER BY w.financial_year DESC, w.withdrawal_date DESC
             LIMIT ? OFFSET ?"
        );
        $i = 1;
        foreach ($params as $v) { $listStmt->bindValue($i++, $v); }
        $listStmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $listStmt->bindValue($i,   $offset,  PDO::PARAM_INT);
        $listStmt->execute();

        return [
            'rows'     => $listStmt->fetchAll(),
            'total'    => $total,
            'pages'    => $total > 0 ? (int)ceil($total/$perPage) : 1,
            'totalAmt' => $totalAmt,
        ];
    }

    /** Available financial years in the table */
    public function getYears(): array
    {
        try {
            return $this->db->query(
                "SELECT DISTINCT financial_year FROM `withdrawals` ORDER BY financial_year DESC"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) { return []; }
    }

    // ── Activity log ─────────────────────────────────────────
    public function log(int $userId, string $action, string $desc = ''): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO `activity_logs`(`user_id`,`action`,`description`,`ip_address`)
                 VALUES (?,?,?,?)"
            );
            $stmt->execute([$userId, $action, $desc, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (PDOException $e) {}
    }
}
