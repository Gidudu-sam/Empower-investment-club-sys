<?php
/**
 * AccountingPeriodModel — Accounting Periods
 * 
 * Represents fiscal/accounting periods within financial years.
 * Periods control when journal entries can be posted.
 */
class AccountingPeriodModel extends Model
{
    protected string $table      = 'accounting_periods';
    protected string $primaryKey = 'id';

    /**
     * Get all accounting periods with financial year info
     */
    public function getAll(): array
    {
        $stmt = $this->db->query("
            SELECT 
                ap.*,
                fy.name as financial_year_name,
                fy.status as financial_year_status
            FROM `accounting_periods` ap
            LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
            ORDER BY ap.start_date DESC, ap.id DESC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Get financial years eligible for a new accounting period
     * (active years, plus the legacy year for historical/test periods)
     */
    public function getFinancialYearsForPeriods(): array
    {
        $stmt = $this->db->query("
            SELECT * FROM financial_years
            WHERE status = 'active' OR is_legacy = 1
            ORDER BY start_date DESC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Get open accounting period that contains the given date
     * 
     * @param string $date Date in Y-m-d format
     * @return array|false Period record or false if none found
     */
    public function getOpenPeriodForDate(string $date): array|false
    {
        $stmt = $this->db->prepare("
            SELECT 
                ap.*,
                fy.status as financial_year_status
            FROM `accounting_periods` ap
            LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
            WHERE ap.status = 'open'
              AND ap.start_date <= ?
              AND ap.end_date >= ?
              AND (fy.status = 'active' OR fy.status IS NULL)
            LIMIT 1
        ");
        $stmt->execute([$date, $date]);
        return $stmt->fetch();
    }

    /**
     * Get period by financial year and date range
     */
    public function findByDateRange(int $financialYearId, string $startDate, string $endDate): array|false
    {
        $stmt = $this->db->prepare("
            SELECT * FROM `accounting_periods`
            WHERE financial_year_id = ?
              AND start_date = ?
              AND end_date = ?
            LIMIT 1
        ");
        $stmt->execute([$financialYearId, $startDate, $endDate]);
        return $stmt->fetch();
    }

    /**
     * Create a new accounting period
     * 
     * @param array $data Period data
     * @return int|false Period ID or false on failure
     */
    public function createPeriod(array $data): int|false
    {
        // Validate required fields
        if (empty($data['financial_year_id']) || 
            empty($data['name']) || 
            empty($data['start_date']) || 
            empty($data['end_date'])) {
            return false;
        }

        // Check for overlapping periods in the same financial year
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as cnt FROM `accounting_periods`
            WHERE financial_year_id = ?
              AND (
                  (start_date <= ? AND end_date >= ?)
                  OR (start_date <= ? AND end_date >= ?)
                  OR (start_date >= ? AND end_date <= ?)
              )
        ");
        $stmt->execute([
            $data['financial_year_id'],
            $data['start_date'], $data['start_date'],
            $data['end_date'], $data['end_date'],
            $data['start_date'], $data['end_date']
        ]);
        
        if ((int)$stmt->fetch()['cnt'] > 0) {
            throw new InvalidArgumentException('Period dates overlap with an existing period in this financial year.');
        }

        $data['status'] = $data['status'] ?? 'open';
        $data['created_at'] = date('Y-m-d H:i:s');

        $id = $this->create($data);
        if ($id !== false && !empty($data['created_by'])) {
            $this->log((int)$data['created_by'], 'accounting_period_created', "Created accounting period \"{$data['name']}\" ({$data['start_date']} to {$data['end_date']})");
        }
        return $id;
    }

    /** Single period joined with its financial year's name/status. */
    public function getPeriodWithYear(int $id): array|false
    {
        $stmt = $this->db->prepare("
            SELECT ap.*, fy.name AS financial_year_name, fy.status AS financial_year_status
            FROM `accounting_periods` ap
            LEFT JOIN `financial_years` fy ON fy.id = ap.financial_year_id
            WHERE ap.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Raw debit/credit totals for every LIVE journal entry in this period --
     * used both for display and the pre-close balance guard. Stage 28: this
     * ran its own independent query, bypassing AccountingReportModel's
     * shared authoritative-population filter entirely -- a real gap the
     * Stage 26 enforcement missed. Restricted to data_classification='live'
     * to match every other accounting report; dummy/unknown entries remain
     * fully preserved in the database, only excluded from this summary.
     */
    public function getPeriodJournalBalance(int $id): array
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(jl.debit),0) AS total_debit, COALESCE(SUM(jl.credit),0) AS total_credit,
                   COUNT(DISTINCT je.id) AS entry_count
            FROM `journal_entries` je
            JOIN `journal_lines` jl ON jl.journal_entry_id = je.id
            WHERE je.accounting_period_id = ? AND je.data_classification = 'live'
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return [
            'total_debit'  => (float)($row['total_debit'] ?? 0),
            'total_credit' => (float)($row['total_credit'] ?? 0),
            'entry_count'  => (int)($row['entry_count'] ?? 0),
        ];
    }

    /**
     * Period summary card figures. Income/expense/net come straight from
     * AccountingReportModel::incomeStatement() (accounting_period_id filter,
     * already supported there) -- no parallel calculation engine.
     */
    public function getPeriodSummary(int $id): array
    {
        $balance = $this->getPeriodJournalBalance($id);
        $reportModel = new AccountingReportModel();
        $income = $reportModel->incomeStatement(['accounting_period_id' => $id]);

        return [
            'journal_entries' => $balance['entry_count'],
            'total_debit'     => $balance['total_debit'],
            'total_credit'    => $balance['total_credit'],
            'total_income'    => $income['total_income'] ?? 0.0,
            'total_expense'   => $income['total_expense'] ?? 0.0,
            'net_result'      => ($income['total_income'] ?? 0.0) - ($income['total_expense'] ?? 0.0),
        ];
    }

    /**
     * Cross-module transaction activity whose date falls inside this
     * period's date range -- read-only, reuses the existing operational
     * tables directly (§14: "use the actual current transaction tables",
     * no duplicate transaction records created). Each row is tagged with
     * its module and whether it is currently journal-linked, so a reader
     * can immediately see the same coverage gap every prior stage's
     * evidence has already documented (0% for Savings/Loans/Repayments/
     * Fees, 100% for Expenses) without this view claiming otherwise.
     */
    public function getPeriodActivity(int $id, array $filters = []): array
    {
        $period = $this->find($id);
        if (!$period) {
            throw new InvalidArgumentException("Accounting period id {$id} does not exist.");
        }
        $start = $period['start_date'];
        $end   = $period['end_date'];
        $moduleFilter = $filters['module'] ?? '';

        $queries = [
            'Savings' => "SELECT s.id, s.transaction_date AS date, s.receipt_number AS reference,
                    CONCAT('Savings ', s.transaction_type) AS description, s.recorded_by AS user_id,
                    (COALESCE(s.credit,0)-COALESCE(s.debit,0)) AS amount, s.journal_entry_id
                FROM savings s WHERE s.transaction_date BETWEEN ? AND ?",
            'Loans' => "SELECT l.id, l.issue_date AS date, l.loan_number AS reference,
                    'Loan disbursement' AS description, NULL AS user_id,
                    l.loan_amount AS amount, l.journal_entry_id
                FROM loans l WHERE l.issue_date BETWEEN ? AND ?",
            'Repayments' => "SELECT r.id, r.payment_date AS date, r.repayment_number AS reference,
                    CONCAT('Repayment (', r.payment_type, ')') AS description, r.received_by AS user_id,
                    r.amount_paid AS amount, r.journal_entry_id
                FROM loan_repayments r WHERE r.payment_date BETWEEN ? AND ?",
            'Withdrawals' => "SELECT w.id, w.withdrawal_date AS date, w.withdrawal_number AS reference,
                    CONCAT('Withdrawal (', w.withdrawal_type, ')') AS description, w.processed_by AS user_id,
                    w.withdrawal_amount AS amount, w.journal_entry_id
                FROM withdrawals w WHERE w.withdrawal_date BETWEEN ? AND ?",
            'Fees' => "SELECT f.id, f.charged_date AS date, f.id AS reference,
                    CONCAT('Fee charge (', f.status, ')') AS description, f.created_by AS user_id,
                    f.amount AS amount, f.journal_entry_id
                FROM member_fees f WHERE f.charged_date BETWEEN ? AND ?",
            'Other Income' => "SELECT oi.id, oi.income_date AS date, oi.reference_number AS reference,
                    'Other income' AS description, oi.recorded_by AS user_id,
                    oi.amount AS amount, oi.journal_entry_id
                FROM other_income_transactions oi WHERE oi.income_date BETWEEN ? AND ?",
            'Expenses' => "SELECT e.id, e.expense_date AS date, e.expense_number AS reference,
                    CONCAT('Expense (', e.status, ')') AS description, e.recorded_by AS user_id,
                    e.amount AS amount, e.journal_entry_id
                FROM expenses e WHERE e.expense_date BETWEEN ? AND ?",
            'Investments' => "SELECT i.id, i.start_date AS date, i.reference AS reference,
                    'Investment' AS description, i.recorded_by AS user_id,
                    i.principal_amount AS amount, i.journal_entry_id
                FROM investments i WHERE i.start_date BETWEEN ? AND ?",
        ];

        $rows = [];
        foreach ($queries as $module => $sql) {
            if ($moduleFilter !== '' && $moduleFilter !== $module) {
                continue;
            }
            try {
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$start, $end]);
                foreach ($stmt->fetchAll() as $r) {
                    $r['module'] = $module;
                    $r['journal_linked'] = !empty($r['journal_entry_id']);
                    $rows[] = $r;
                }
            } catch (PDOException $e) {
                // A family with an unexpected/legacy column shape must never
                // break the whole activity view -- skip it, don't fabricate
                // rows, and don't let one module's error hide the rest.
                continue;
            }
        }

        usort($rows, fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
        return $rows;
    }

    /**
     * Journal-level activity for this period, straight from
     * journal_entries/journal_lines. Stage 28: restricted to
     * data_classification='live' for the same reason as
     * getPeriodJournalBalance() above -- this listing is a normal,
     * unlabeled admin view, not a dedicated forensic tool, so it should
     * show the same authoritative population every other report does.
     */
    public function getPeriodJournalActivity(int $id, array $filters = []): array
    {
        $where = ['je.accounting_period_id = ?', "je.data_classification = 'live'"];
        $params = [$id];
        if (!empty($filters['module'])) {
            $where[] = 'je.source_module = ?';
            $params[] = $filters['module'];
        }

        $sql = "
            SELECT je.id, je.entry_number, je.entry_date, je.source_module, je.source_reference_type,
                   je.source_reference_id, je.description, je.reversed, je.reversal_of_id,
                   je.created_by, u.full_name AS created_by_name,
                   COALESCE(SUM(jl.debit),0) AS total_debit, COALESCE(SUM(jl.credit),0) AS total_credit,
                   COUNT(jl.id) AS line_count
            FROM `journal_entries` je
            LEFT JOIN `journal_lines` jl ON jl.journal_entry_id = je.id
            LEFT JOIN `users` u ON u.id = je.created_by
            WHERE " . implode(' AND ', $where) . "
            GROUP BY je.id
            ORDER BY je.entry_date DESC, je.id DESC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Audit trail for this period (create/close/reopen), from activity_logs, filtered by period name+dates appearing in the description. */
    public function getPeriodAuditActivity(array $period): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM `activity_logs`
            WHERE action IN ('accounting_period_created','accounting_period_closed','accounting_period_reopened')
              AND description LIKE ?
            ORDER BY created_at DESC, id DESC
        ");
        $stmt->execute(['%"' . $period['name'] . '"%']);
        return $stmt->fetchAll();
    }

    private function log(int $userId, string $action, string $description): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `activity_logs`(`user_id`,`action`,`description`,`ip_address`) VALUES (?,?,?,?)"
        );
        $stmt->execute([$userId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    }

    /**
     * Close an accounting period.
     *
     * Stage 20: requires a non-empty reason, and refuses to close a period
     * whose own journal activity is unbalanced (a pre-close integrity
     * check -- never "fixes" anything found, only blocks the close and
     * reports why). Does not touch any journal_entries/journal_lines row.
     *
     * @param int $id Period ID
     * @param int $userId User performing the close
     * @param string $reason Mandatory closing reason (audit trail)
     * @return bool Success
     */
    public function closePeriod(int $id, int $userId, string $reason): bool
    {
        $period = $this->find($id);
        if (!$period) {
            throw new InvalidArgumentException("Accounting period id {$id} does not exist.");
        }
        if ($period['status'] === 'closed') {
            throw new InvalidArgumentException('This accounting period is already closed.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to close an accounting period.');
        }

        $balance = $this->getPeriodJournalBalance($id);
        if (abs($balance['total_debit'] - $balance['total_credit']) > 0.01) {
            throw new InvalidArgumentException(sprintf(
                'This period\'s journal activity is not balanced (debits %s, credits %s) -- closing has been blocked. This must be investigated before the period can be closed; nothing has been changed.',
                number_format($balance['total_debit'], 2), number_format($balance['total_credit'], 2)
            ));
        }

        $ok = $this->update($id, [
            'status'       => 'closed',
            'closed_by'    => $userId,
            'closed_at'    => date('Y-m-d H:i:s'),
            'close_reason' => $reason,
        ]);

        if ($ok) {
            $this->log($userId, 'accounting_period_closed', "Closed accounting period \"{$period['name']}\" ({$period['start_date']} to {$period['end_date']}) — {$reason}");
        }

        return $ok;
    }

    /**
     * Reopen a closed accounting period. Never alters any journal or
     * transaction row -- only the period's own status/audit fields.
     * Caller is responsible for the permission check (Stage 24 will
     * formalize a dedicated permission; today this is admin-only, matching
     * every other destructive/control action in this application).
     *
     * @param int $id Period ID
     * @param int $userId User performing the reopen
     * @param string $reason Mandatory reopen reason (audit trail)
     */
    public function reopenPeriod(int $id, int $userId, string $reason): bool
    {
        $period = $this->find($id);
        if (!$period) {
            throw new InvalidArgumentException("Accounting period id {$id} does not exist.");
        }
        if ($period['status'] === 'open') {
            throw new InvalidArgumentException('This accounting period is already open.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to reopen an accounting period.');
        }

        $ok = $this->update($id, [
            'status'        => 'open',
            'reopened_by'   => $userId,
            'reopened_at'   => date('Y-m-d H:i:s'),
            'reopen_reason' => $reason,
        ]);

        if ($ok) {
            $this->log($userId, 'accounting_period_reopened', "Reopened accounting period \"{$period['name']}\" ({$period['start_date']} to {$period['end_date']}) — {$reason}");
        }

        return $ok;
    }

    /**
     * Get open periods for a financial year
     */
    public function getOpenPeriodsForYear(int $financialYearId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM `accounting_periods`
            WHERE financial_year_id = ?
              AND status = 'open'
            ORDER BY start_date
        ");
        $stmt->execute([$financialYearId]);
        return $stmt->fetchAll();
    }

    /**
     * Check if a period is open
     */
    public function isOpen(int $id): bool
    {
        $period = $this->find($id);
        return $period && $period['status'] === 'open';
    }
}
