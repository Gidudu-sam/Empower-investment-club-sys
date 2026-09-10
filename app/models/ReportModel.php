<?php
/**
 * ReportModel — Comprehensive Reports & Analytics
 */
class ReportModel extends Model
{
    protected string $table      = 'members';
    protected string $primaryKey = 'id';

    // ================================================================
    // DASHBOARD SUMMARY STATS
    // ================================================================

    public function getDashboardStats(): array
    {
        try {
            $stats = [];

            // Members
            $stats['total_members']    = (int)$this->db->query("SELECT COUNT(*) FROM members")->fetchColumn();
            $stats['active_members']   = (int)$this->db->query("SELECT COUNT(*) FROM members WHERE status='active'")->fetchColumn();
            // Stage: was previously (total - active), which silently folded
            // dormant members into "inactive" -- a real member with
            // status='dormant' was being mislabeled. Counted explicitly now.
            $stats['inactive_members'] = (int)$this->db->query("SELECT COUNT(*) FROM members WHERE status='inactive'")->fetchColumn();
            $stats['dormant_members']  = (int)$this->db->query("SELECT COUNT(*) FROM members WHERE status='dormant'")->fetchColumn();

            // Savings
            $stats['total_savings'] = (float)$this->db->query("SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings")->fetchColumn();
            $stats['month_savings'] = (float)$this->db->query(
                "SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings
                 WHERE MONTH(transaction_date)=MONTH(CURDATE()) AND YEAR(transaction_date)=YEAR(CURDATE())"
            )->fetchColumn();

            // Shares (retained amounts from withdrawals)
            $stats['total_shares'] = (float)$this->db->query("SELECT COALESCE(SUM(retained_amount),0) FROM withdrawals")->fetchColumn();

            // Loans
            $stats['active_loans'] = (int)$this->db->query("SELECT COUNT(*) FROM loans WHERE status IN('active','overdue')")->fetchColumn();
            $stats['outstanding_balance'] = (float)$this->db->query("SELECT COALESCE(SUM(outstanding),0) FROM loans WHERE status IN('active','overdue')")->fetchColumn();

            // Withdrawals
            $stats['total_withdrawals'] = (float)$this->db->query("SELECT COALESCE(SUM(withdrawal_amount),0) FROM withdrawals")->fetchColumn();

            // Interest earned
            // Stage 8: excludes loans not yet disbursed (draft/pending_approval/rejected)
            // -- an undisbursed loan's projected interest must not appear as "earned".
            $stats['total_interest'] = (float)$this->db->query("SELECT COALESCE(SUM(interest_amount),0) FROM loans WHERE status NOT IN ('draft','pending_approval','rejected')")->fetchColumn();

            // Cash collected today (savings + repayments)
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings WHERE transaction_date = CURDATE()"
            );
            $stmt->execute();
            $todaySavings = (float)$stmt->fetchColumn();

            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(amount_paid),0) FROM loan_repayments WHERE payment_date = CURDATE()"
            );
            $stmt->execute();
            $todayRepayments = (float)$stmt->fetchColumn();

            $stats['cash_today'] = $todaySavings + $todayRepayments;

            return $stats;
        } catch (PDOException $e) {
            return [
                'total_members' => 0, 'active_members' => 0, 'inactive_members' => 0, 'dormant_members' => 0,
                'total_savings' => 0, 'month_savings' => 0, 'total_shares' => 0, 'active_loans' => 0,
                'outstanding_balance' => 0, 'total_withdrawals' => 0,
                'total_interest' => 0, 'cash_today' => 0,
            ];
        }
    }

    // ================================================================
    // MEMBER REPORTS
    // ================================================================

    public function getMemberReport(): array
    {
        try {
            $report = [];
            $report['total']    = (int)$this->db->query("SELECT COUNT(*) FROM members")->fetchColumn();
            $report['active']   = (int)$this->db->query("SELECT COUNT(*) FROM members WHERE status='active'")->fetchColumn();
            $report['inactive'] = $report['total'] - $report['active'];

            // New members this month
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM members
                 WHERE MONTH(join_date)=MONTH(CURDATE()) AND YEAR(join_date)=YEAR(CURDATE())"
            );
            $stmt->execute();
            $report['new_this_month'] = (int)$stmt->fetchColumn();

            // Members who have never saved
            $report['never_saved'] = (int)$this->db->query(
                "SELECT COUNT(*) FROM members m
                 WHERE NOT EXISTS (SELECT 1 FROM savings s WHERE s.member_id = m.id)"
            )->fetchColumn();

            // Members with active loans
            $report['with_active_loans'] = (int)$this->db->query(
                "SELECT COUNT(DISTINCT member_id) FROM loans WHERE status IN('active','overdue')"
            )->fetchColumn();

            // Members with outstanding loans
            $report['with_outstanding'] = (int)$this->db->query(
                "SELECT COUNT(DISTINCT member_id) FROM loans WHERE outstanding > 0 AND status IN('active','overdue')"
            )->fetchColumn();

            // Members eligible for annual withdrawal (active members with savings)
            $report['eligible_withdrawal'] = (int)$this->db->query(
                "SELECT COUNT(*) FROM members m
                 WHERE m.status='active'
                 AND EXISTS (SELECT 1 FROM savings s WHERE s.member_id = m.id)
                 AND NOT EXISTS (
                     SELECT 1 FROM withdrawals w
                     WHERE w.member_id = m.id AND w.financial_year = YEAR(CURDATE())
                 )"
            )->fetchColumn();

            // Member list with details
            $report['members'] = $this->db->query(
                "SELECT m.*,
                        COALESCE((SELECT SUM(COALESCE(s.credit,0)-COALESCE(s.debit,0)) FROM savings s WHERE s.member_id=m.id),0) AS total_savings,
                        (SELECT COUNT(*) FROM loans l WHERE l.member_id=m.id AND l.status IN('active','overdue')) AS active_loans
                 FROM members m
                 ORDER BY m.member_number ASC"
            )->fetchAll();

            return $report;
        } catch (PDOException $e) {
            return ['total'=>0,'active'=>0,'inactive'=>0,'new_this_month'=>0,
                    'never_saved'=>0,'with_active_loans'=>0,'with_outstanding'=>0,
                    'eligible_withdrawal'=>0,'members'=>[]];
        }
    }

    // ================================================================
    // SAVINGS REPORTS
    // ================================================================

    public function getSavingsReport(string $type = 'monthly', string $dateFrom = '', string $dateTo = '', int $year = 0, int $month = 0): array
    {
        try {
            $where = [];
            $params = [];

            if (!$year) $year = (int)date('Y');
            if (!$month) $month = (int)date('n');

            switch ($type) {
                case 'daily':
                    $date = $dateFrom ?: date('Y-m-d');
                    $where[] = 's.transaction_date = ?';
                    $params[] = $date;
                    break;
                case 'weekly':
                    $from = $dateFrom ?: date('Y-m-d', strtotime('monday this week'));
                    $to   = $dateTo ?: date('Y-m-d', strtotime('sunday this week'));
                    $where[] = 's.transaction_date BETWEEN ? AND ?';
                    $params[] = $from;
                    $params[] = $to;
                    break;
                case 'monthly':
                    $where[] = 'MONTH(s.transaction_date) = ? AND YEAR(s.transaction_date) = ?';
                    $params[] = $month;
                    $params[] = $year;
                    break;
                case 'annual':
                    $where[] = 'YEAR(s.transaction_date) = ?';
                    $params[] = $year;
                    break;
                case 'financial_year':
                    $where[] = 's.financial_year = ?';
                    $params[] = (string)$year;
                    break;
                case 'custom':
                    if ($dateFrom) { $where[] = 's.transaction_date >= ?'; $params[] = $dateFrom; }
                    if ($dateTo)   { $where[] = 's.transaction_date <= ?'; $params[] = $dateTo; }
                    break;
            }

            $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            // Summary stats
            $sumStmt = $this->db->prepare(
                "SELECT COUNT(*) AS total_deposits,
                        COALESCE(SUM(COALESCE(s.credit,0)-COALESCE(s.debit,0)),0) AS total_amount,
                        COALESCE(AVG(COALESCE(s.credit,0)-COALESCE(s.debit,0)),0) AS avg_deposit,
                        COALESCE(MAX(COALESCE(s.credit,0)-COALESCE(s.debit,0)),0) AS highest_deposit,
                        COALESCE(MIN(COALESCE(s.credit,0)-COALESCE(s.debit,0)),0) AS lowest_deposit,
                        COUNT(DISTINCT s.member_id) AS members_saved
                 FROM savings s {$whereSQL}"
            );
            $sumStmt->execute($params);
            $summary = $sumStmt->fetch();

            // Total active members (for "did not save" calc)
            $totalActive = (int)$this->db->query("SELECT COUNT(*) FROM members WHERE status='active'")->fetchColumn();
            $summary['members_not_saved'] = $totalActive - (int)$summary['members_saved'];

            // Detailed records
            $listStmt = $this->db->prepare(
                "SELECT s.*, (COALESCE(s.credit,0)-COALESCE(s.debit,0)) AS amount,
                        m.first_name, m.last_name, m.member_number
                 FROM savings s
                 JOIN members m ON m.id = s.member_id
                 {$whereSQL}
                 ORDER BY s.transaction_date DESC, s.id DESC"
            );
            $listStmt->execute($params);
            $summary['records'] = $listStmt->fetchAll();

            return $summary;
        } catch (PDOException $e) {
            return ['total_deposits'=>0,'total_amount'=>0,'avg_deposit'=>0,
                    'highest_deposit'=>0,'lowest_deposit'=>0,'members_saved'=>0,
                    'members_not_saved'=>0,'records'=>[]];
        }
    }

    // ================================================================
    // LOAN REPORTS
    // ================================================================

    public function getLoanReport(string $dateFrom = '', string $dateTo = ''): array
    {
        try {
            $where = [];
            $params = [];

            if ($dateFrom) { $where[] = 'l.issue_date >= ?'; $params[] = $dateFrom; }
            if ($dateTo)   { $where[] = 'l.issue_date <= ?'; $params[] = $dateTo; }

            $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $report = [];

            // All-time stats. Stage 8: "issued"/"loaned" mean actually
            // disbursed -- excludes draft/pending_approval/rejected loans
            // that have never had money move.
            $report['total_issued'] = (int)$this->db->query("SELECT COUNT(*) FROM loans WHERE status NOT IN ('draft','pending_approval','rejected')")->fetchColumn();
            $report['total_amount_loaned'] = (float)$this->db->query("SELECT COALESCE(SUM(loan_amount),0) FROM loans WHERE status NOT IN ('draft','pending_approval','rejected')")->fetchColumn();
            $report['active_loans'] = (int)$this->db->query("SELECT COUNT(*) FROM loans WHERE status='active'")->fetchColumn();
            $report['completed_loans'] = (int)$this->db->query("SELECT COUNT(*) FROM loans WHERE status='completed'")->fetchColumn();
            $report['overdue_loans'] = (int)$this->db->query(
                "SELECT COUNT(*) FROM loans WHERE status='overdue' OR (status='active' AND due_date < CURDATE())"
            )->fetchColumn();
            $report['outstanding_balance'] = (float)$this->db->query(
                "SELECT COALESCE(SUM(outstanding),0) FROM loans WHERE status IN('active','overdue')"
            )->fetchColumn();
            $report['total_repayments'] = (float)$this->db->query(
                "SELECT COALESCE(SUM(amount_paid),0) FROM loan_repayments"
            )->fetchColumn();
            $report['total_interest'] = (float)$this->db->query(
                "SELECT COALESCE(SUM(interest_amount),0) FROM loans WHERE status NOT IN ('draft','pending_approval','rejected')"
            )->fetchColumn();
            $report['avg_loan_amount'] = (float)$this->db->query(
                "SELECT COALESCE(AVG(loan_amount),0) FROM loans WHERE status NOT IN ('draft','pending_approval','rejected')"
            )->fetchColumn();
            $report['largest_loan'] = (float)$this->db->query(
                "SELECT COALESCE(MAX(loan_amount),0) FROM loans WHERE status NOT IN ('draft','pending_approval','rejected')"
            )->fetchColumn();

            // Filtered loan list
            $listStmt = $this->db->prepare(
                "SELECT l.*, m.first_name, m.last_name, m.member_number,
                        DATEDIFF(l.due_date, CURDATE()) AS days_remaining
                 FROM loans l
                 JOIN members m ON m.id = l.member_id
                 {$whereSQL}
                 ORDER BY l.issue_date DESC"
            );
            $listStmt->execute($params);
            $report['loans'] = $listStmt->fetchAll();

            return $report;
        } catch (PDOException $e) {
            return ['total_issued'=>0,'total_amount_loaned'=>0,'active_loans'=>0,
                    'completed_loans'=>0,'overdue_loans'=>0,'outstanding_balance'=>0,
                    'total_repayments'=>0,'total_interest'=>0,'avg_loan_amount'=>0,
                    'largest_loan'=>0,'loans'=>[]];
        }
    }

    public function getLoanAgingReport(): array
    {
        $empty = fn() => ['count' => 0, 'amount' => 0.0, 'loans' => []];
        $buckets = [
            'current'      => $empty(),
            'days_1_30'    => $empty(),
            'days_31_60'   => $empty(),
            'days_61_90'   => $empty(),
            'days_90_plus' => $empty(),
        ];

        try {
            $loans = $this->db->query(
                "SELECT l.*, m.first_name, m.last_name, m.member_number,
                        DATEDIFF(CURDATE(), l.due_date) AS days_overdue
                 FROM loans l
                 JOIN members m ON m.id = l.member_id
                 WHERE l.status IN ('active','overdue') AND l.outstanding > 0
                 ORDER BY l.due_date ASC"
            )->fetchAll();

            $totalCount = 0;
            $totalAmount = 0.0;

            foreach ($loans as $l) {
                $days = (int)$l['days_overdue'];
                if ($days <= 0)       { $key = 'current'; }
                elseif ($days <= 30)  { $key = 'days_1_30'; }
                elseif ($days <= 60)  { $key = 'days_31_60'; }
                elseif ($days <= 90)  { $key = 'days_61_90'; }
                else                  { $key = 'days_90_plus'; }

                $buckets[$key]['count']++;
                $buckets[$key]['amount'] += (float)$l['outstanding'];
                $buckets[$key]['loans'][] = $l;

                $totalCount++;
                $totalAmount += (float)$l['outstanding'];
            }

            $buckets['total_count'] = $totalCount;
            $buckets['total_amount'] = $totalAmount;

            return $buckets;
        } catch (PDOException $e) {
            $buckets['total_count'] = 0;
            $buckets['total_amount'] = 0.0;
            return $buckets;
        }
    }

    // ================================================================
    // LOAN REPAYMENT REPORTS
    // ================================================================

    public function getRepaymentReport(string $type = 'monthly', string $dateFrom = '', string $dateTo = '', int $year = 0, int $month = 0): array
    {
        try {
            $where = [];
            $params = [];

            if (!$year) $year = (int)date('Y');
            if (!$month) $month = (int)date('n');

            switch ($type) {
                case 'daily':
                    $date = $dateFrom ?: date('Y-m-d');
                    $where[] = 'r.payment_date = ?';
                    $params[] = $date;
                    break;
                case 'weekly':
                    $from = $dateFrom ?: date('Y-m-d', strtotime('monday this week'));
                    $to   = $dateTo ?: date('Y-m-d', strtotime('sunday this week'));
                    $where[] = 'r.payment_date BETWEEN ? AND ?';
                    $params[] = $from;
                    $params[] = $to;
                    break;
                case 'monthly':
                    $where[] = 'MONTH(r.payment_date) = ? AND YEAR(r.payment_date) = ?';
                    $params[] = $month;
                    $params[] = $year;
                    break;
                case 'custom':
                    if ($dateFrom) { $where[] = 'r.payment_date >= ?'; $params[] = $dateFrom; }
                    if ($dateTo)   { $where[] = 'r.payment_date <= ?'; $params[] = $dateTo; }
                    break;
            }

            $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            // Summary
            $sumStmt = $this->db->prepare(
                "SELECT COUNT(*) AS total_payments,
                        COALESCE(SUM(r.amount_paid),0) AS total_repaid,
                        COUNT(DISTINCT r.member_id) AS members_paid
                 FROM loan_repayments r {$whereSQL}"
            );
            $sumStmt->execute($params);
            $summary = $sumStmt->fetch();

            // Interest earned from loans that had repayments in this period
            $intStmt = $this->db->prepare(
                "SELECT COALESCE(SUM(l.interest_amount),0)
                 FROM loans l
                 WHERE l.id IN (SELECT DISTINCT r.loan_id FROM loan_repayments r {$whereSQL})"
            );
            $intStmt->execute($params);
            $summary['interest_earned'] = (float)$intStmt->fetchColumn();

            // Detailed records
            $listStmt = $this->db->prepare(
                "SELECT r.*, l.loan_number, l.outstanding AS loan_outstanding,
                        m.first_name, m.last_name, m.member_number
                 FROM loan_repayments r
                 JOIN loans l ON l.id = r.loan_id
                 JOIN members m ON m.id = r.member_id
                 {$whereSQL}
                 ORDER BY r.payment_date DESC, r.id DESC"
            );
            $listStmt->execute($params);
            $summary['records'] = $listStmt->fetchAll();

            return $summary;
        } catch (PDOException $e) {
            return ['total_payments'=>0,'total_repaid'=>0,'members_paid'=>0,
                    'interest_earned'=>0,'records'=>[]];
        }
    }

    // ================================================================
    // SHARE REPORTS
    // ================================================================

    public function getShareReport(): array
    {
        try {
            $report = [];
            $report['total_share_capital'] = (float)$this->db->query(
                "SELECT COALESCE(SUM(retained_amount),0) FROM withdrawals"
            )->fetchColumn();

            $report['total_shareholders'] = (int)$this->db->query(
                "SELECT COUNT(DISTINCT member_id) FROM withdrawals WHERE retained_amount > 0"
            )->fetchColumn();

            $report['avg_shares'] = $report['total_shareholders'] > 0
                ? round($report['total_share_capital'] / $report['total_shareholders'], 2)
                : 0;

            // Top shareholders
            $report['top_shareholders'] = $this->db->query(
                "SELECT m.first_name, m.last_name, m.member_number,
                        SUM(w.retained_amount) AS total_shares
                 FROM withdrawals w
                 JOIN members m ON m.id = w.member_id
                 WHERE w.retained_amount > 0
                 GROUP BY w.member_id, m.first_name, m.last_name, m.member_number
                 ORDER BY total_shares DESC
                 LIMIT 20"
            )->fetchAll();

            return $report;
        } catch (PDOException $e) {
            return ['total_share_capital'=>0,'total_shareholders'=>0,'avg_shares'=>0,'top_shareholders'=>[]];
        }
    }

    // ================================================================
    // WITHDRAWAL REPORTS
    // ================================================================

    public function getWithdrawalReport(int $year = 0): array
    {
        try {
            if (!$year) $year = (int)date('Y');

            $report = [];

            $stmt = $this->db->prepare(
                "SELECT w.*, m.first_name, m.last_name, m.member_number
                 FROM withdrawals w
                 JOIN members m ON m.id = w.member_id
                 WHERE w.financial_year = ?
                 ORDER BY w.withdrawal_date DESC"
            );
            $stmt->execute([$year]);
            $report['withdrawals'] = $stmt->fetchAll();

            // Totals for the year
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS total_count,
                        COALESCE(SUM(withdrawal_amount),0) AS total_withdrawn,
                        COALESCE(SUM(retained_amount),0) AS total_retained
                 FROM withdrawals WHERE financial_year = ?"
            );
            $stmt->execute([$year]);
            $totals = $stmt->fetch();
            $report['total_count']     = (int)$totals['total_count'];
            $report['total_withdrawn'] = (float)$totals['total_withdrawn'];
            $report['total_retained']  = (float)$totals['total_retained'];

            // Members who have not yet made their ANNUAL COMPULSORY
            // withdrawal this year. Type-scoped (Stage 17 Part E) -- a
            // voluntary withdrawal (which may legitimately repeat multiple
            // times a year) must not exclude a member from this list; the
            // report's intent is specifically about the annual entitlement.
            $stmt = $this->db->prepare(
                "SELECT m.first_name, m.last_name, m.member_number,
                        COALESCE((SELECT SUM(COALESCE(s.credit,0)-COALESCE(s.debit,0)) FROM savings s WHERE s.member_id=m.id),0) AS total_savings
                 FROM members m
                 WHERE m.status='active'
                 AND NOT EXISTS (SELECT 1 FROM withdrawals w WHERE w.member_id=m.id AND w.financial_year=? AND w.withdrawal_type='annual_compulsory')
                 ORDER BY m.member_number"
            );
            $stmt->execute([$year]);
            $report['not_withdrawn'] = $stmt->fetchAll();

            // Available years
            $report['years'] = $this->db->query(
                "SELECT DISTINCT financial_year FROM withdrawals ORDER BY financial_year DESC"
            )->fetchAll(PDO::FETCH_COLUMN);

            return $report;
        } catch (PDOException $e) {
            return ['withdrawals'=>[],'total_count'=>0,'total_withdrawn'=>0,
                    'total_retained'=>0,'not_withdrawn'=>[],'years'=>[]];
        }
    }

    // ================================================================
    // FINANCIAL SUMMARY
    // ================================================================

    public function getFinancialSummary(): array
    {
        try {
            $report = [];
            $report['total_savings'] = (float)$this->db->query("SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings")->fetchColumn();
            $report['total_share_capital'] = (float)$this->db->query("SELECT COALESCE(SUM(retained_amount),0) FROM withdrawals")->fetchColumn();
            $report['total_loans_issued'] = (float)$this->db->query("SELECT COALESCE(SUM(loan_amount),0) FROM loans WHERE status NOT IN ('draft','pending_approval','rejected')")->fetchColumn();
            $report['outstanding_balance'] = (float)$this->db->query("SELECT COALESCE(SUM(outstanding),0) FROM loans WHERE status IN('active','overdue')")->fetchColumn();
            $report['interest_earned'] = (float)$this->db->query("SELECT COALESCE(SUM(interest_amount),0) FROM loans WHERE status NOT IN ('draft','pending_approval','rejected')")->fetchColumn();
            $report['withdrawals_paid'] = (float)$this->db->query("SELECT COALESCE(SUM(withdrawal_amount),0) FROM withdrawals")->fetchColumn();

            // Net Club Position = Savings + Shares + Outstanding Loans + Interest - Withdrawals
            $report['net_position'] = $report['total_savings'] + $report['total_share_capital']
                                    + $report['outstanding_balance'] + $report['interest_earned']
                                    - $report['withdrawals_paid'];

            // Monthly trends (last 12 months)
            $report['monthly_savings'] = $this->db->query(
                "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month,
                        SUM(COALESCE(credit,0)-COALESCE(debit,0)) AS total
                 FROM savings
                 WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                 GROUP BY month ORDER BY month ASC"
            )->fetchAll();

            $report['monthly_loans'] = $this->db->query(
                "SELECT DATE_FORMAT(issue_date, '%Y-%m') AS month,
                        COUNT(*) AS count, SUM(loan_amount) AS total
                 FROM loans
                 WHERE issue_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                   AND status NOT IN ('draft','pending_approval','rejected')
                 GROUP BY month ORDER BY month ASC"
            )->fetchAll();

            $report['monthly_repayments'] = $this->db->query(
                "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month,
                        SUM(amount_paid) AS total
                 FROM loan_repayments
                 WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                 GROUP BY month ORDER BY month ASC"
            )->fetchAll();

            // Member growth (last 12 months)
            $report['monthly_members'] = $this->db->query(
                "SELECT DATE_FORMAT(join_date, '%Y-%m') AS month,
                        COUNT(*) AS new_members
                 FROM members
                 WHERE join_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                 GROUP BY month ORDER BY month ASC"
            )->fetchAll();

            return $report;
        } catch (PDOException $e) {
            return ['total_savings'=>0,'total_share_capital'=>0,'total_loans_issued'=>0,
                    'outstanding_balance'=>0,'interest_earned'=>0,'withdrawals_paid'=>0,
                    'net_position'=>0,'monthly_savings'=>[],'monthly_loans'=>[],
                    'monthly_repayments'=>[],'monthly_members'=>[]];
        }
    }

    // ================================================================
    // CHART DATA
    // ================================================================

    public function getChartData(): array
    {
        try {
            $data = [];

            // Savings growth (last 12 months)
            $data['savings_growth'] = $this->db->query(
                "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month,
                        DATE_FORMAT(transaction_date, '%b %Y') AS label,
                        SUM(COALESCE(credit,0)-COALESCE(debit,0)) AS total
                 FROM savings
                 WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                 GROUP BY month, label ORDER BY month ASC"
            )->fetchAll();

            // Loan issuance (last 12 months)
            $data['loan_issuance'] = $this->db->query(
                "SELECT DATE_FORMAT(issue_date, '%Y-%m') AS month,
                        DATE_FORMAT(issue_date, '%b %Y') AS label,
                        COUNT(*) AS count, SUM(loan_amount) AS total
                 FROM loans
                 WHERE issue_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                 GROUP BY month, label ORDER BY month ASC"
            )->fetchAll();

            // Loan repayments (last 12 months)
            $data['repayments'] = $this->db->query(
                "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month,
                        DATE_FORMAT(payment_date, '%b %Y') AS label,
                        SUM(amount_paid) AS total
                 FROM loan_repayments
                 WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                 GROUP BY month, label ORDER BY month ASC"
            )->fetchAll();

            // Member growth (last 12 months)
            $data['member_growth'] = $this->db->query(
                "SELECT DATE_FORMAT(join_date, '%Y-%m') AS month,
                        DATE_FORMAT(join_date, '%b %Y') AS label,
                        COUNT(*) AS new_members
                 FROM members
                 WHERE join_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                 GROUP BY month, label ORDER BY month ASC"
            )->fetchAll();

            return $data;
        } catch (PDOException $e) {
            return ['savings_growth'=>[],'loan_issuance'=>[],'repayments'=>[],'member_growth'=>[]];
        }
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
