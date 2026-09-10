<?php
/**
 * WeeklyReportModel — Weekly Reports for WhatsApp sharing
 * Covers: Savings Deposits, Loan Disbursements, Loan Repayments, Overdue Loans
 */
class WeeklyReportModel extends Model
{
    protected string $table      = 'savings';
    protected string $primaryKey = 'id';

    /**
     * Get the reporting week boundaries (Monday to Sunday).
     * If a date is provided, calculate the week containing that date.
     */
    public function getWeekBounds(?string $date = null): array
    {
        $ref = $date ? strtotime($date) : time();
        $dow = (int)date('N', $ref); // 1=Mon, 7=Sun
        $monday = strtotime('-' . ($dow - 1) . ' days', $ref);
        $sunday = strtotime('+' . (7 - $dow) . ' days', $ref);
        return [
            'start' => date('Y-m-d', $monday),
            'end'   => date('Y-m-d', $sunday),
            'label' => date('jS M', $monday) . ' – ' . date('jS M Y', $sunday),
        ];
    }

    /**
     * Get available weeks that have savings data.
     */
    public function getAvailableWeeks(int $limit = 20): array
    {
        try {
            $stmt = $this->db->query(
                "SELECT DISTINCT 
                    DATE_SUB(transaction_date, INTERVAL (DAYOFWEEK(transaction_date) - 2 + 7) % 7 DAY) AS week_start,
                    DATE_ADD(DATE_SUB(transaction_date, INTERVAL (DAYOFWEEK(transaction_date) - 2 + 7) % 7 DAY), INTERVAL 6 DAY) AS week_end
                 FROM savings
                 ORDER BY week_start DESC
                 LIMIT {$limit}"
            );
            $weeks = [];
            foreach ($stmt->fetchAll() as $row) {
                $weeks[] = [
                    'start' => $row['week_start'],
                    'end'   => $row['week_end'],
                    'label' => date('jS M', strtotime($row['week_start'])) . ' – ' . date('jS M Y', strtotime($row['week_end'])),
                ];
            }
            return $weeks;
        } catch (PDOException $e) { return []; }
    }

    // ================================================================
    // WEEKLY SAVINGS DEPOSITS
    // ================================================================

    /**
     * Get unique members who saved during the specified week.
     */
    public function getWeeklySavingsDeposits(string $weekStart, string $weekEnd): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT m.id, m.first_name, m.last_name, m.member_number, m.gender,
                        SUM(COALESCE(s.credit,0)-COALESCE(s.debit,0)) AS total_deposited,
                        COUNT(s.id) AS deposit_count,
                        MAX(s.transaction_date) AS last_deposit_date
                 FROM savings s
                 JOIN members m ON m.id = s.member_id
                 WHERE s.transaction_date BETWEEN ? AND ?
                 GROUP BY m.id, m.first_name, m.last_name, m.member_number, m.gender
                 ORDER BY m.first_name ASC, m.last_name ASC"
            );
            $stmt->execute([$weekStart, $weekEnd]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    /**
     * Get weekly savings summary stats.
     */
    public function getWeeklySavingsSummary(string $weekStart, string $weekEnd): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(DISTINCT member_id) AS total_members,
                        COUNT(*) AS total_transactions,
                        COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)), 0) AS total_amount
                 FROM savings
                 WHERE transaction_date BETWEEN ? AND ?"
            );
            $stmt->execute([$weekStart, $weekEnd]);
            return $stmt->fetch() ?: ['total_members' => 0, 'total_transactions' => 0, 'total_amount' => 0];
        } catch (PDOException $e) {
            return ['total_members' => 0, 'total_transactions' => 0, 'total_amount' => 0];
        }
    }

    // ================================================================
    // WEEKLY LOAN DISBURSEMENTS
    // ================================================================

    public function getWeeklyLoanDisbursements(string $weekStart, string $weekEnd): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT l.*, m.first_name, m.last_name, m.member_number, m.gender
                 FROM loans l
                 JOIN members m ON m.id = l.member_id
                 WHERE l.issue_date BETWEEN ? AND ?
                 ORDER BY l.issue_date ASC"
            );
            $stmt->execute([$weekStart, $weekEnd]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function getWeeklyLoanDisbursementSummary(string $weekStart, string $weekEnd): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS total_loans,
                        COUNT(DISTINCT member_id) AS total_members,
                        COALESCE(SUM(loan_amount), 0) AS total_amount
                 FROM loans
                 WHERE issue_date BETWEEN ? AND ?"
            );
            $stmt->execute([$weekStart, $weekEnd]);
            return $stmt->fetch() ?: ['total_loans' => 0, 'total_members' => 0, 'total_amount' => 0];
        } catch (PDOException $e) {
            return ['total_loans' => 0, 'total_members' => 0, 'total_amount' => 0];
        }
    }

    // ================================================================
    // WEEKLY LOAN REPAYMENTS
    // ================================================================

    public function getWeeklyLoanRepayments(string $weekStart, string $weekEnd): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT m.id AS member_id, m.first_name, m.last_name, m.member_number, m.gender,
                        SUM(r.amount_paid) AS total_paid,
                        COUNT(r.id) AS payment_count
                 FROM loan_repayments r
                 JOIN members m ON m.id = r.member_id
                 WHERE r.payment_date BETWEEN ? AND ?
                 GROUP BY m.id, m.first_name, m.last_name, m.member_number, m.gender
                 ORDER BY m.first_name ASC, m.last_name ASC"
            );
            $stmt->execute([$weekStart, $weekEnd]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    public function getWeeklyRepaymentSummary(string $weekStart, string $weekEnd): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(DISTINCT member_id) AS total_members,
                        COUNT(*) AS total_payments,
                        COALESCE(SUM(amount_paid), 0) AS total_amount
                 FROM loan_repayments
                 WHERE payment_date BETWEEN ? AND ?"
            );
            $stmt->execute([$weekStart, $weekEnd]);
            return $stmt->fetch() ?: ['total_members' => 0, 'total_payments' => 0, 'total_amount' => 0];
        } catch (PDOException $e) {
            return ['total_members' => 0, 'total_payments' => 0, 'total_amount' => 0];
        }
    }

    // ================================================================
    // OVERDUE LOANS
    // ================================================================

    public function getOverdueLoans(): array
    {
        try {
            return $this->db->query(
                "SELECT l.*, m.first_name, m.last_name, m.member_number, m.gender,
                        DATEDIFF(CURDATE(), l.due_date) AS days_overdue
                 FROM loans l
                 JOIN members m ON m.id = l.member_id
                 WHERE (l.status = 'overdue' OR (l.status = 'active' AND l.due_date < CURDATE()))
                 AND l.outstanding > 0
                 ORDER BY l.due_date ASC"
            )->fetchAll();
        } catch (PDOException $e) { return []; }
    }

    // ================================================================
    // ACTIVITY LOG
    // ================================================================

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
