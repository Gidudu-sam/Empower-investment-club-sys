<?php
/**
 * MemberSavingsAccountModel — the standalone Savings Account entity.
 *
 * account_type -> ownership_type is a fixed, structural mapping (never
 * trusted from caller input): compulsory/voluntary are always
 * individual, joint is always joint, corporate is always corporate.
 * This guarantees the invariant by construction rather than by a
 * validation check that could drift out of sync.
 *
 * Balance is never stored — always SUM(credit)-SUM(debit) over `savings`
 * rows for this account's savings_account_id, per the approved design
 * (avoids a second source of truth that could drift from the ledger).
 *
 * Enum values (account_type/ownership_type/status) are explicitly
 * whitelisted in PHP before every write, since this database's sql_mode
 * lacks STRICT_TRANS_TABLES and would otherwise silently coerce an
 * invalid ENUM value to '' instead of erroring (confirmed in Stage 1).
 */
class MemberSavingsAccountModel extends Model
{
    protected string $table      = 'member_savings_accounts';
    protected string $primaryKey = 'id';

    public const ACCOUNT_TYPES = ['compulsory', 'voluntary', 'joint', 'corporate', 'fixed_deposit'];
    public const OWNERSHIP_TYPES = ['individual', 'joint', 'corporate'];
    public const STATUSES = ['active', 'dormant', 'closed', 'matured'];

    private const TYPE_OWNERSHIP_MAP = [
        'compulsory'    => 'individual',
        'voluntary'     => 'individual',
        'joint'         => 'joint',
        'corporate'     => 'corporate',
        'fixed_deposit' => 'individual',
    ];

    private const PREFIX_MAP = [
        'compulsory'    => 'CS',
        'voluntary'     => 'VS',
        'joint'         => 'JS',
        'corporate'     => 'CORP',
        'fixed_deposit' => 'FD',
    ];

    /** Qualification policy: compulsory-only deposits, count + amount thresholds. */
    private const QUALIFYING_DEPOSIT_COUNT = 2;
    private const QUALIFYING_DEPOSIT_AMOUNT = 40000.00;

    /** Dormancy: months of no activity on this specific account. */
    private const DORMANCY_MONTHS = 6;

    private SavingsAccountHolderModel $holderModel;

    public function __construct()
    {
        parent::__construct();
        $this->holderModel = new SavingsAccountHolderModel();
    }

    // ----------------------------------------------------------------
    // Creation
    // ----------------------------------------------------------------

    /**
     * Create a savings account and its holder(s) atomically.
     *
     * @param array $data    { account_type, status?, opened_date, created_by? }
     * @param array $holders [{ member_id?, organization_id?, role }, ...]
     */
    public function createAccount(array $data, array $holders, int $userId): int
    {
        $accountType = $data['account_type'] ?? '';
        if (!in_array($accountType, self::ACCOUNT_TYPES, true)) {
            throw new InvalidArgumentException("Invalid account_type \"{$accountType}\".");
        }
        $ownershipType = self::TYPE_OWNERSHIP_MAP[$accountType];

        $status = $data['status'] ?? 'active';
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid status \"{$status}\".");
        }
        if (empty($data['opened_date'])) {
            throw new InvalidArgumentException('opened_date is required.');
        }

        $this->validateHolderShape($accountType, $holders);

        // Application-level invariant: one compulsory account per member.
        // Enforced here (not just at the registration call site) so any
        // future caller is protected uniformly. Application-level per the
        // approved design, since ownership is indirect (via the holders
        // table), not a direct member_id column a DB UNIQUE could target.
        if ($accountType === 'compulsory') {
            foreach ($holders as $h) {
                if (empty($h['member_id'])) {
                    continue;
                }
                $existing = $this->holderModel->getMemberAccounts((int)$h['member_id']);
                foreach ($existing as $acc) {
                    if ($acc['account_type'] === 'compulsory') {
                        throw new InvalidArgumentException("Member id {$h['member_id']} already has a compulsory savings account ({$acc['account_number']}).");
                    }
                }
            }
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        // Fixed Deposit only: the caller (createFixedDepositAccount()) has
        // already run these through calculateFixedDeposit(), so this is a
        // presence/shape check, not a recalculation -- these seven columns
        // stay NULL for every other account type regardless of $data.
        $fixedDepositFields = [
            'principal_amount' => null, 'deposit_date' => null, 'term_months' => null,
            'maturity_date' => null, 'interest_rate' => null,
            'expected_interest' => null, 'expected_maturity_amount' => null,
        ];
        if ($accountType === 'fixed_deposit') {
            foreach (array_keys($fixedDepositFields) as $key) {
                if (!isset($data[$key])) {
                    throw new InvalidArgumentException("Fixed Deposit requires \"{$key}\" to be set.");
                }
                $fixedDepositFields[$key] = $data[$key];
            }
        }

        try {
            $accountNumber = $this->nextAccountNumber($accountType);

            $id = $this->create(array_merge([
                'account_number'       => $accountNumber,
                'account_type'         => $accountType,
                'ownership_type'       => $ownershipType,
                'status'               => $status,
                'opened_date'          => $data['opened_date'],
                'created_by'           => $userId,
            ], $fixedDepositFields));
            if ($id === false) {
                throw new RuntimeException('Failed to create savings account.');
            }

            foreach ($holders as $h) {
                $this->holderModel->addHolder(
                    $id,
                    $h['member_id'] ?? null,
                    $h['organization_id'] ?? null,
                    $h['role']
                );
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
            return $id;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // ----------------------------------------------------------------
    // FIXED DEPOSIT (2026-09)
    //
    // A bank-style lump-sum term deposit. Simple interest only, annual
    // rate, locked on the account at opening
    // so a later change to the default rate setting never alters an
    // existing deposit's terms. No top-ups, no early withdrawal (enforced
    // in SavingsAccountController, not here).
    // ----------------------------------------------------------------

    /** The only term lengths offered today -- see
     *  SavingsAccountController::FIXED_DEPOSIT_TERMS for the single
     *  authoritative copy used by both the form and server-side
     *  validation. Kept here too only as the calculation's own sanity
     *  bound (a defensively-rejected value never reaches this method in
     *  practice, since the controller validates first). */
    public const FIXED_DEPOSIT_MIN_TERM_MONTHS = 1;
    public const FIXED_DEPOSIT_MAX_TERM_MONTHS = 120;

    /**
     * Simple interest, annual rate, pro-rated by term:
     *   interest = principal x (annualRatePct/100) x (termMonths/12)
     *   maturity_amount = principal + interest
     *   maturity_date = deposit_date + termMonths (calendar-aware, via
     *   DateTime, never an approximated day count -- same technique
     *   already used for loans.due_date throughout this system).
     *
     * This is the ONE place these figures are computed. The opening
     * controller calls this to build the row it saves; nothing else
     * (client JS, a submitted hidden field) is ever trusted for these
     * values.
     */
    public function calculateFixedDeposit(float $principal, float $annualRatePct, int $termMonths, string $depositDate): array
    {
        if ($principal <= 0) {
            throw new InvalidArgumentException('Principal amount must be greater than zero.');
        }
        if ($annualRatePct < 0) {
            throw new InvalidArgumentException('Interest rate cannot be negative.');
        }
        if ($termMonths < self::FIXED_DEPOSIT_MIN_TERM_MONTHS || $termMonths > self::FIXED_DEPOSIT_MAX_TERM_MONTHS) {
            throw new InvalidArgumentException("Term must be between " . self::FIXED_DEPOSIT_MIN_TERM_MONTHS . " and " . self::FIXED_DEPOSIT_MAX_TERM_MONTHS . " months.");
        }
        $depositDateTime = DateTime::createFromFormat('Y-m-d', $depositDate);
        if (!$depositDateTime) {
            throw new InvalidArgumentException('Invalid deposit date.');
        }

        $interest = round($principal * ($annualRatePct / 100) * ($termMonths / 12), 2);
        $maturityAmount = round($principal + $interest, 2);

        // PHP's DateTime::modify("+N months") overflows on a month-end
        // deposit date (31 Jan + 1 month lands on 3 Mar, not 28 Feb) --
        // detect that overflow and clamp back to the last day of the
        // intended month, rather than silently accepting the rolled-over
        // date.
        $originalDay = (int)$depositDateTime->format('d');
        $maturity = (clone $depositDateTime)->modify("+{$termMonths} months");
        if ((int)$maturity->format('d') !== $originalDay) {
            $maturity->modify('last day of previous month');
        }
        $maturityDate = $maturity->format('Y-m-d');

        return [
            'principal_amount'         => round($principal, 2),
            'interest_rate'            => $annualRatePct,
            'term_months'              => $termMonths,
            'deposit_date'             => $depositDate,
            'maturity_date'            => $maturityDate,
            'expected_interest'        => $interest,
            'expected_maturity_amount' => $maturityAmount,
        ];
    }

    /**
     * Opens a Fixed Deposit account AND records its one allowed opening
     * deposit atomically (unlike every other account type, which opens as
     * an empty shell and receives deposits later through the generic
     * flow). Returns the created account id and the deposit/journal
     * result, so the caller can show the confirmation without a second
     * query. Rolls back everything -- account, deposit, journal -- on any
     * failure: never an account without its principal, never a principal
     * deposit without a journal entry.
     *
     * $calc must be calculateFixedDeposit()'s own output -- the caller
     * (the controller) computes it once, server-side, from submitted
     * principal/rate/term/date, and this method persists exactly that,
     * never re-deriving or trusting any other source for these figures.
     */
    public function createFixedDepositAccount(array $calc, int $memberId, string $paymentMethod, int $userId): array
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $accountId = $this->createAccount(
                [
                    'account_type'             => 'fixed_deposit',
                    'opened_date'              => $calc['deposit_date'],
                    'principal_amount'         => $calc['principal_amount'],
                    'deposit_date'             => $calc['deposit_date'],
                    'term_months'              => $calc['term_months'],
                    'maturity_date'            => $calc['maturity_date'],
                    'interest_rate'            => $calc['interest_rate'],
                    'expected_interest'        => $calc['expected_interest'],
                    'expected_maturity_amount' => $calc['expected_maturity_amount'],
                ],
                [['member_id' => $memberId, 'role' => 'primary']],
                $userId
            );

            require_once APP_PATH . '/models/SavingsModel.php';
            $savingsModel = new SavingsModel();
            $depositResult = $savingsModel->recordDepositWithPosting([
                'savings_account_id'        => $accountId,
                'member_id'                 => $memberId,
                'transaction_type'          => 'deposit',
                'amount'                    => $calc['principal_amount'],
                'payment_method'            => $paymentMethod,
                'transaction_date'          => $calc['deposit_date'],
                'financial_year'            => date('Y', strtotime($calc['deposit_date'])),
                'is_fixed_deposit_principal'=> 1,
                'notes'                     => 'Fixed Deposit opening principal.',
                'receipt_number'            => $savingsModel->generateReceiptNumber(),
                'recorded_by'               => $userId,
            ], $userId);

            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['account_id' => $accountId] + $depositResult;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Idempotent, transaction-free bulk status sync: any active Fixed
     * Deposit whose maturity_date has arrived becomes 'matured'. Mirrors
     * LoanModel::syncOverdueStatus()'s exact precedent (a plain bulk
     * UPDATE run on read, not a scheduler this application doesn't have)
     * -- safe to call on every request, running it twice never changes
     * anything the second time since the WHERE clause only ever matches
     * rows still in 'active'. One activity_logs entry is written per
     * account actually transitioned (Stage FD-2 audit requirement),
     * naming the system as the actor since this runs on any authenticated
     * page view, not a specific user action.
     */
    public function syncMaturedFixedDeposits(): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, account_number FROM `member_savings_accounts`
                 WHERE account_type='fixed_deposit' AND status='active' AND maturity_date <= CURDATE()"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();
            if (empty($rows)) {
                return 0;
            }

            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $this->db->prepare(
                "UPDATE `member_savings_accounts` SET status='matured' WHERE id IN ({$placeholders})"
            )->execute($ids);

            foreach ($rows as $row) {
                $this->log(0, 'fixed_deposit_matured', "Fixed Deposit {$row['account_number']} reached maturity -- status active -> matured.");
            }
            return count($rows);
        } catch (PDOException $e) {
            return 0;
        }
    }

    /** Same activity_logs pattern already used identically by
     *  AccountingPeriodModel::log()/FinancialYearModel::log() -- reused
     *  here rather than inventing a second audit mechanism. user_id=0 is
     *  used for the one system-driven event (maturity sync); every other
     *  FD-2 event is logged with a real acting user's id. */
    private function log(int $userId, string $action, string $description): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `activity_logs`(`user_id`,`action`,`description`,`ip_address`) VALUES (?,?,?,?)"
        );
        $stmt->execute([$userId ?: null, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    }

    /**
     * Validate the intended holder set against account_type BEFORE any
     * write happens (fail fast, no partial account-without-holders state).
     * SavingsAccountHolderModel::addHolder() re-validates each row
     * individually at insert time as defense-in-depth.
     */
    private function validateHolderShape(string $accountType, array $holders): void
    {
        if (empty($holders)) {
            throw new InvalidArgumentException('An account must have at least one holder.');
        }

        foreach ($holders as $h) {
            $role = $h['role'] ?? '';
            if (!in_array($role, SavingsAccountHolderModel::ROLES, true)) {
                throw new InvalidArgumentException("Invalid holder role \"{$role}\".");
            }
            $hasMember = !empty($h['member_id']);
            $hasOrg    = !empty($h['organization_id']);
            if ($hasMember === $hasOrg) {
                throw new InvalidArgumentException('Each holder must reference exactly one of member_id or organization_id.');
            }
        }

        $memberHolders = array_filter($holders, fn($h) => !empty($h['member_id']));
        $orgHolders    = array_filter($holders, fn($h) => !empty($h['organization_id']));
        $primaryCount  = count(array_filter($holders, fn($h) => ($h['role'] ?? '') === 'primary'));

        switch ($accountType) {
            case 'compulsory':
            case 'voluntary':
            case 'fixed_deposit':
                if (count($holders) !== 1 || count($memberHolders) !== 1 || ($holders[0]['role'] ?? '') !== 'primary') {
                    throw new InvalidArgumentException('An individual (compulsory/voluntary/fixed_deposit) account must have exactly one holder with role "primary".');
                }
                break;
            case 'joint':
                if (count($memberHolders) < 2 || count($orgHolders) > 0) {
                    throw new InvalidArgumentException('A joint account must have at least two member holders and no organization holder.');
                }
                if ($primaryCount > 1) {
                    throw new InvalidArgumentException('A joint account may have at most one primary holder.');
                }
                $memberIds = array_column($memberHolders, 'member_id');
                if (count($memberIds) !== count(array_unique($memberIds))) {
                    throw new InvalidArgumentException('A joint account cannot list the same member twice.');
                }
                break;
            case 'corporate':
                if (count($orgHolders) !== 1 || count($memberHolders) > 0 || $holders[0]['role'] !== 'organization') {
                    throw new InvalidArgumentException('A corporate account must have exactly one organization holder with role "organization".');
                }
                break;
        }
    }

    private function nextAccountNumber(string $accountType): string
    {
        $prefix = self::PREFIX_MAP[$accountType] ?? null;
        if ($prefix === null) {
            throw new InvalidArgumentException("No account-number prefix configured for type \"{$accountType}\".");
        }
        $stmt = $this->db->prepare("SELECT last_number FROM `journal_number_sequences` WHERE prefix = ? FOR UPDATE");
        $stmt->execute([$prefix]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No journal_number_sequences row for prefix '{$prefix}'.");
        }
        $next = (int)$row['last_number'] + 1;
        $this->db->prepare("UPDATE `journal_number_sequences` SET last_number = ? WHERE prefix = ?")->execute([$next, $prefix]);
        return sprintf('%s-%06d', $prefix, $next);
    }

    // ----------------------------------------------------------------
    // Retrieval
    // ----------------------------------------------------------------

    public function getAccount(int $id): array|false
    {
        return $this->find($id);
    }

    public function getAccountByNumber(string $accountNumber): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM `member_savings_accounts` WHERE account_number = ? LIMIT 1");
        $stmt->execute([$accountNumber]);
        return $stmt->fetch();
    }

    public function getAccounts(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['account_type'])) {
            $where[] = 'account_type = ?';
            $params[] = $filters['account_type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $stmt = $this->db->prepare("SELECT * FROM `member_savings_accounts` {$whereSql} ORDER BY created_at DESC");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getMemberAccounts(int $memberId): array
    {
        return $this->holderModel->getMemberAccounts($memberId);
    }

    public function getAccountHolders(int $accountId): array
    {
        return $this->holderModel->getAccountHolders($accountId);
    }

    // ----------------------------------------------------------------
    // Balance / transactions — always derived from `savings`, never stored
    // ----------------------------------------------------------------

    public function getAccountBalance(int $accountId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)), 0) FROM `savings` WHERE savings_account_id = ?"
        );
        $stmt->execute([$accountId]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Authoritative total of all credit (deposit) rows for an account.
     * Does not depend on pagination — reads the full savings table.
     */
    public function getTotalDeposits(int $accountId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(COALESCE(credit,0)), 0) FROM `savings` WHERE savings_account_id = ? AND COALESCE(credit,0) > 0"
        );
        $stmt->execute([$accountId]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Authoritative total of all debit (withdrawal) rows for an account.
     * Does not depend on pagination — reads the full savings table.
     */
    public function getTotalWithdrawals(int $accountId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(COALESCE(debit,0)), 0) FROM `savings` WHERE savings_account_id = ? AND COALESCE(debit,0) > 0"
        );
        $stmt->execute([$accountId]);
        return (float)$stmt->fetchColumn();
    }

    /** Account balance immediately before $asOfDate -- i.e. the correct
     *  "opening balance" for a statement period starting on that date.
     *  Simpler than the member-level equivalent (StatementModel::
     *  openingBalanceAsOf()): withdrawals for a savings account are
     *  already recorded as ordinary debit rows in `savings` (confirmed by
     *  reading WithdrawalModel::processAnnualCompulsory()/processVoluntary(),
     *  both call SavingsModel::create() against this same table), so there
     *  is no second `withdrawals` table to union in here. */
    public function accountOpeningBalanceAsOf(int $accountId, string $asOfDate): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)), 0) FROM `savings`
             WHERE savings_account_id = ? AND transaction_date < ?"
        );
        $stmt->execute([$accountId, $asOfDate]);
        return (float)$stmt->fetchColumn();
    }

    /** Transactions for one account within an inclusive date range
     *  (oldest first, matching statement convention), used for a
     *  period-scoped statement. $limit is a safety cap, not a page size --
     *  a real date range keeps result sets small in practice. */
    public function getAccountTransactionsInRange(int $accountId, string $dateFrom, string $dateTo, int $limit = 2000): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, (COALESCE(s.credit,0)-COALESCE(s.debit,0)) AS amount, u.full_name AS cashier_name
             FROM `savings` s
             LEFT JOIN `users` u ON u.id = s.recorded_by
             WHERE s.savings_account_id = ? AND s.transaction_date BETWEEN ? AND ?
             ORDER BY s.transaction_date ASC, s.id ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $accountId, PDO::PARAM_INT);
        $stmt->bindValue(2, $dateFrom);
        $stmt->bindValue(3, $dateTo);
        $stmt->bindValue(4, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getAccountTransactions(int $accountId, int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, (COALESCE(s.credit,0)-COALESCE(s.debit,0)) AS amount, u.full_name AS cashier_name
             FROM `savings` s
             LEFT JOIN `users` u ON u.id = s.recorded_by
             WHERE s.savings_account_id = ?
             ORDER BY s.transaction_date DESC, s.id DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $accountId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Overview: count + balance per account_type (for the Savings Overview page). */
    public function getAccountTypeTotals(): array
    {
        $totals = [];
        foreach (self::ACCOUNT_TYPES as $type) {
            $count = $this->db->prepare("SELECT COUNT(*) FROM `member_savings_accounts` WHERE account_type = ?");
            $count->execute([$type]);
            $balance = $this->db->prepare(
                "SELECT COALESCE(SUM(COALESCE(s.credit,0)-COALESCE(s.debit,0)), 0)
                 FROM `savings` s
                 JOIN `member_savings_accounts` a ON a.id = s.savings_account_id
                 WHERE a.account_type = ?"
            );
            $balance->execute([$type]);
            $totals[$type] = ['count' => (int)$count->fetchColumn(), 'balance' => (float)$balance->fetchColumn()];
        }
        return $totals;
    }

    public function getMemberSavingsSummary(int $memberId): array
    {
        $accounts = $this->getMemberAccounts($memberId);
        $summary = [];
        $total = 0.0;
        foreach ($accounts as $a) {
            $balance = $this->getAccountBalance((int)$a['id']);
            $summary[] = array_merge($a, ['balance' => $balance]);
            $total += $balance;
        }
        return ['accounts' => $summary, 'total' => $total];
    }

    // ----------------------------------------------------------------
    // Status
    // ----------------------------------------------------------------

    public function updateStatus(int $accountId, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid status \"{$status}\".");
        }
        $stmt = $this->db->prepare("UPDATE `member_savings_accounts` SET status = ? WHERE id = ?");
        return $stmt->execute([$status, $accountId]);
    }

    public function closeAccount(int $accountId, string $closedDate): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE `member_savings_accounts` SET status = 'closed', closed_date = ? WHERE id = ?"
        );
        return $stmt->execute([$closedDate, $accountId]);
    }

    // ----------------------------------------------------------------
    // Compulsory qualification — evaluated ONLY from this account's own
    // deposit rows, never member_id, never other account types.
    // ----------------------------------------------------------------

    /** Read-only: current qualification progress, does not write anything. */
    public function checkCompulsoryQualification(int $accountId): array
    {
        $account = $this->find($accountId);
        if (!$account) {
            throw new InvalidArgumentException("Savings account id {$accountId} does not exist.");
        }
        if ($account['account_type'] !== 'compulsory') {
            throw new InvalidArgumentException('Qualification only applies to compulsory accounts.');
        }

        if ($account['qualification_met_date'] !== null) {
            return [
                'qualified' => true,
                'qualification_met_date' => $account['qualification_met_date'],
                'deposit_count' => null,
                'deposit_total' => null,
            ];
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) c, COALESCE(SUM(credit),0) t FROM `savings`
             WHERE savings_account_id = ? AND transaction_type = 'deposit'"
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();

        return [
            'qualified' => (int)$row['c'] >= self::QUALIFYING_DEPOSIT_COUNT && (float)$row['t'] >= self::QUALIFYING_DEPOSIT_AMOUNT,
            'qualification_met_date' => null,
            'deposit_count' => (int)$row['c'],
            'deposit_total' => (float)$row['t'],
        ];
    }

    /**
     * Idempotent: if qualification_met_date is already set, returns it
     * unchanged and writes nothing. Otherwise walks this account's
     * compulsory deposits chronologically and records the date of the
     * specific deposit that first satisfies both thresholds together.
     */
    public function recordQualification(int $accountId): ?string
    {
        $account = $this->find($accountId);
        if (!$account) {
            throw new InvalidArgumentException("Savings account id {$accountId} does not exist.");
        }
        if ($account['account_type'] !== 'compulsory') {
            throw new InvalidArgumentException('Qualification only applies to compulsory accounts.');
        }
        if ($account['qualification_met_date'] !== null) {
            return $account['qualification_met_date'];
        }

        $stmt = $this->db->prepare(
            "SELECT transaction_date, credit FROM `savings`
             WHERE savings_account_id = ? AND transaction_type = 'deposit'
             ORDER BY transaction_date ASC, id ASC"
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll();

        $count = 0;
        $sum = 0.0;
        foreach ($rows as $r) {
            $count++;
            $sum += (float)$r['credit'];
            if ($count >= self::QUALIFYING_DEPOSIT_COUNT && $sum >= self::QUALIFYING_DEPOSIT_AMOUNT) {
                $this->db->prepare(
                    "UPDATE `member_savings_accounts` SET qualification_met_date = ? WHERE id = ? AND qualification_met_date IS NULL"
                )->execute([$r['transaction_date'], $accountId]);
                return $r['transaction_date'];
            }
        }
        return null;
    }

    // ----------------------------------------------------------------
    // Dormancy — account-specific, never member-level
    // ----------------------------------------------------------------

    public function getLastActivity(int $accountId): ?string
    {
        $stmt = $this->db->prepare("SELECT MAX(transaction_date) FROM `savings` WHERE savings_account_id = ?");
        $stmt->execute([$accountId]);
        return $stmt->fetchColumn() ?: null;
    }

    public function isDormant(int $accountId): bool
    {
        $account = $this->find($accountId);
        if (!$account) {
            throw new InvalidArgumentException("Savings account id {$accountId} does not exist.");
        }
        $lastActivity = $this->getLastActivity($accountId) ?? $account['opened_date'];
        $cutoff = (new DateTime())->modify('-' . self::DORMANCY_MONTHS . ' months');
        return new DateTime($lastActivity) < $cutoff;
    }

    // ----------------------------------------------------------------
    // Savings tenure — used by loan eligibility rules (Stage 9)
    // ----------------------------------------------------------------

    /**
     * Months since the member's earliest still-open compulsory savings
     * account was opened (fractional). Returns null if the member has no
     * compulsory account at all, so callers can distinguish "not eligible
     * because no account" from "not eligible because too new".
     */
    public function compulsorySavingsTenureMonths(int $memberId): ?float
    {
        $stmt = $this->db->prepare(
            "SELECT a.`opened_date`
             FROM `member_savings_accounts` a
             JOIN `savings_account_holders` h ON h.`account_id` = a.`id`
             WHERE h.`member_id` = ? AND a.`account_type` = 'compulsory' AND a.`status` != 'closed'
             ORDER BY a.`opened_date` ASC LIMIT 1"
        );
        $stmt->execute([$memberId]);
        $openedDate = $stmt->fetchColumn();
        if (!$openedDate) {
            return null;
        }
        $opened = new DateTime($openedDate);
        $now    = new DateTime();
        $days   = (int)$opened->diff($now)->days;
        return $days / 30.4375; // average days/month, fine-grained enough for a ">" comparison
    }
}
