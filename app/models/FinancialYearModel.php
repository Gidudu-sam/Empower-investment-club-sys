<?php
/**
 * FinancialYearModel — canonical financial_years read/write layer.
 *
 * Stage 2 (Financial Years Consolidation): create/edit/activate were added
 * here to make this the single authoritative implementation, replacing the
 * weaker equivalents that used to live only in SettingsModel (no validation
 * at all, and -- critically -- SettingsModel::activateFinancialYear() could
 * silently "activate" a CLOSED year, completely bypassing the audited
 * reopen() workflow below). SettingsModel's old methods are left in place,
 * unused, rather than deleted -- see SettingsController's financial-year
 * actions, now thin redirects/delegates onto this model.
 */
class FinancialYearModel extends Model
{
    protected string $table      = 'financial_years';
    protected string $primaryKey = 'id';

    public function getAll(): array
    {
        return $this->db->query("SELECT * FROM `financial_years` ORDER BY start_date DESC")->fetchAll();
    }

    private function validDate(string $d): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    }

    /**
     * Create a new financial year. Always starts 'pending' (matching the
     * legacy default) -- it becomes usable only once explicitly activated.
     * end_date > start_date is a new, minimal guard: neither the legacy
     * implementation nor accounting_periods enforces date ordering anywhere
     * today, but a year with end before start cannot resolve any accounting
     * period against it, so this is basic input sanity, not an invented
     * business policy.
     */
    public function createYear(string $name, string $start, string $end, int $userId): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Financial year name is required.');
        }
        if (!$this->validDate($start) || !$this->validDate($end)) {
            throw new InvalidArgumentException('Enter valid start and end dates (YYYY-MM-DD).');
        }
        if ($end <= $start) {
            throw new InvalidArgumentException('End date must be after the start date.');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO `financial_years` (`name`,`start_date`,`end_date`,`status`,`created_by`)
             VALUES (?,?,?,'pending',?)"
        );
        $stmt->execute([$name, $start, $end, $userId]);
        $id = (int)$this->db->lastInsertId();

        $this->log($userId, 'financial_year_created', "Created financial year \"{$name}\" ({$start} to {$end})");
        return $id;
    }

    /**
     * Edit an existing financial year's name/dates. A CLOSED year is
     * read-only here -- new rule, not present in the legacy implementation
     * (which allowed editing regardless of status). Retroactively changing
     * the date range of an already-closed, audited year could silently
     * misalign historical period/journal resolution; reopen it first
     * (audited, reasoned) if a genuine correction is required.
     */
    public function updateYear(int $id, string $name, string $start, string $end, int $userId): bool
    {
        $year = $this->find($id);
        if (!$year) {
            throw new InvalidArgumentException("Financial year id {$id} does not exist.");
        }
        if ($year['status'] === 'closed') {
            throw new InvalidArgumentException('This financial year is closed and cannot be edited. Reopen it first if a change is genuinely required.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Financial year name is required.');
        }
        if (!$this->validDate($start) || !$this->validDate($end)) {
            throw new InvalidArgumentException('Enter valid start and end dates (YYYY-MM-DD).');
        }
        if ($end <= $start) {
            throw new InvalidArgumentException('End date must be after the start date.');
        }

        $ok = $this->update($id, ['name' => $name, 'start_date' => $start, 'end_date' => $end]);
        if ($ok) {
            $this->log($userId, 'financial_year_updated', "Updated financial year \"{$name}\" ({$start} to {$end})");
        }
        return $ok;
    }

    /**
     * Activate a financial year -- preserves the legacy behavior's single
     * business rule that actually mattered (exactly one active year at a
     * time: any other 'active' year is demoted to 'pending' first, in the
     * same transaction). Unlike the legacy version, a CLOSED year cannot be
     * activated directly -- it must go through reopenYear() so the action
     * is reasoned and audited, closing a real bypass the legacy path left
     * open (it could silently resurrect a closed year's active status with
     * no reason and no audit trail at all).
     */
    public function activateYear(int $id, int $userId): bool
    {
        $year = $this->find($id);
        if (!$year) {
            throw new InvalidArgumentException("Financial year id {$id} does not exist.");
        }
        if ($year['status'] === 'active') {
            throw new InvalidArgumentException('This financial year is already active.');
        }
        if ($year['status'] === 'closed') {
            throw new InvalidArgumentException('A closed financial year cannot be activated directly -- use Reopen instead, which requires a reason and is audited.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->exec("UPDATE `financial_years` SET `status`='pending' WHERE `status`='active'");
            $ok = $this->update($id, ['status' => 'active']);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($ok) {
            $this->log($userId, 'financial_year_activated', "Activated financial year \"{$year['name']}\" ({$year['start_date']} to {$year['end_date']})");
        }
        return $ok;
    }

    public function getPeriodsForYear(int $yearId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `accounting_periods` WHERE financial_year_id = ? ORDER BY start_date");
        $stmt->execute([$yearId]);
        return $stmt->fetchAll();
    }

    /**
     * Year-level summary. Income/expense/net come from
     * AccountingReportModel::incomeStatement() (financial_year_id filter,
     * already supported there) -- no parallel calculation engine.
     * Stage 28: this method's own debit/credit/entry_count query ran
     * independently of that shared filter, a gap the Stage 26 enforcement
     * missed. Restricted to data_classification='live' to match.
     */
    public function getYearSummary(int $yearId): array
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(jl.debit),0) AS total_debit, COALESCE(SUM(jl.credit),0) AS total_credit,
                   COUNT(DISTINCT je.id) AS entry_count
            FROM `journal_entries` je
            JOIN `journal_lines` jl ON jl.journal_entry_id = je.id
            WHERE je.financial_year_id = ? AND je.data_classification = 'live'
        ");
        $stmt->execute([$yearId]);
        $balance = $stmt->fetch();

        $reportModel = new AccountingReportModel();
        $income = $reportModel->incomeStatement(['financial_year_id' => $yearId]);

        return [
            'journal_entries' => (int)($balance['entry_count'] ?? 0),
            'total_debit'     => (float)($balance['total_debit'] ?? 0),
            'total_credit'    => (float)($balance['total_credit'] ?? 0),
            'total_income'    => $income['total_income'] ?? 0.0,
            'total_expense'   => $income['total_expense'] ?? 0.0,
            'net_result'      => ($income['total_income'] ?? 0.0) - ($income['total_expense'] ?? 0.0),
        ];
    }

    /**
     * Close a financial year. Requires every one of its accounting
     * periods to already be closed, and the year's own journal activity
     * to be balanced -- both checked fresh, neither auto-fixed. Does not
     * post any year-end/retained-earnings entry (explicitly out of scope,
     * per the brief -- "Do not create retained-earnings entries
     * automatically").
     */
    public function closeYear(int $id, int $userId, string $reason): bool
    {
        $year = $this->find($id);
        if (!$year) {
            throw new InvalidArgumentException("Financial year id {$id} does not exist.");
        }
        if ($year['status'] === 'closed') {
            throw new InvalidArgumentException('This financial year is already closed.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to close a financial year.');
        }

        $openPeriods = $this->db->prepare("SELECT COUNT(*) FROM `accounting_periods` WHERE financial_year_id = ? AND status = 'open'");
        $openPeriods->execute([$id]);
        if ((int)$openPeriods->fetchColumn() > 0) {
            throw new InvalidArgumentException('All accounting periods within this financial year must be closed before the year itself can be closed.');
        }

        $summary = $this->getYearSummary($id);
        if (abs($summary['total_debit'] - $summary['total_credit']) > 0.01) {
            throw new InvalidArgumentException(sprintf(
                'This financial year\'s journal activity is not balanced (debits %s, credits %s) -- closing has been blocked. Nothing has been changed.',
                number_format($summary['total_debit'], 2), number_format($summary['total_credit'], 2)
            ));
        }

        $ok = $this->update($id, [
            'status'       => 'closed',
            'closed_by'    => $userId,
            'closed_at'    => date('Y-m-d H:i:s'),
            'close_reason' => $reason,
        ]);

        if ($ok) {
            $this->log($userId, 'financial_year_closed', "Closed financial year \"{$year['name']}\" ({$year['start_date']} to {$year['end_date']}) — {$reason}");
        }

        return $ok;
    }

    public function reopenYear(int $id, int $userId, string $reason): bool
    {
        $year = $this->find($id);
        if (!$year) {
            throw new InvalidArgumentException("Financial year id {$id} does not exist.");
        }
        if ($year['status'] !== 'closed') {
            throw new InvalidArgumentException('This financial year is not closed.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to reopen a financial year.');
        }

        $ok = $this->update($id, [
            'status'        => 'active',
            'reopened_by'   => $userId,
            'reopened_at'   => date('Y-m-d H:i:s'),
            'reopen_reason' => $reason,
        ]);

        if ($ok) {
            $this->log($userId, 'financial_year_reopened', "Reopened financial year \"{$year['name']}\" ({$year['start_date']} to {$year['end_date']}) — {$reason}");
        }

        return $ok;
    }

    public function getAuditActivity(array $year): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM `activity_logs`
            WHERE action IN ('financial_year_closed','financial_year_reopened')
              AND description LIKE ?
            ORDER BY created_at DESC, id DESC
        ");
        $stmt->execute(['%"' . $year['name'] . '"%']);
        return $stmt->fetchAll();
    }

    private function log(int $userId, string $action, string $description): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `activity_logs`(`user_id`,`action`,`description`,`ip_address`) VALUES (?,?,?,?)"
        );
        $stmt->execute([$userId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    }
}
