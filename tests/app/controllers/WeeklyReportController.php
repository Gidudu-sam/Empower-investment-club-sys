<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/WeeklyReportModel.php';

/**
 * WeeklyReportController — Weekly Reports with WhatsApp sharing
 */
class WeeklyReportController extends Controller
{
    private WeeklyReportModel $model;

    public function __construct()
    {
        // Stage 1 security remediation: same gap as ReportController -- no
        // role check existed at all. Restricted to staff roles.
        // Office Admin audit (2026-09): office_admin is admitted here ONLY
        // for savings() below -- loans()/repayments()/overdue() each carry
        // their own explicit block for office_admin, since those drift
        // toward loan/financial oversight, which is deliberately not part
        // of this role. Do not widen this constructor's intent by removing
        // those per-action checks.
        // Loans Officer role-refinement (2026-09): the mirror image --
        // loans_officer is admitted here ONLY for loans()/repayments()/
        // overdue() -- savings() carries its own explicit block for
        // loans_officer, since Weekly Savings is not part of this role's
        // scope (Office Administrator's Weekly Savings access is unchanged).
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'treasurer', 'cashier', 'viewer', 'chairman', 'office_admin', 'loans_officer'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view reports.');
        }

        $this->model = new WeeklyReportModel();
    }

    /** Office Admin gets Weekly Savings only -- loans/repayments/overdue
     *  are excluded here, one call each, right at the top of those actions. */
    private function blockOfficeAdmin(): void
    {
        if (Session::hasRole(['office_admin'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view this report.');
        }
    }

    /** Loans Officer gets Weekly Loans/Repayments/Overdue only -- savings()
     *  carries this explicit block, mirroring blockOfficeAdmin() above. */
    private function blockLoansOfficer(): void
    {
        if (Session::hasRole(['loans_officer'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view this report.');
        }
    }

    // ----------------------------------------------------------------
    // WEEKLY SAVINGS DEPOSITS
    // ----------------------------------------------------------------
    public function savings(): void
    {
        Session::requireAuth();
        $this->blockLoansOfficer();

        $weekDate = $_GET['week'] ?? null;
        $week     = $this->model->getWeekBounds($weekDate);
        $members  = $this->model->getWeeklySavingsDeposits($week['start'], $week['end']);
        $summary  = $this->model->getWeeklySavingsSummary($week['start'], $week['end']);
        $weeks    = $this->model->getAvailableWeeks(20);

        $this->model->log((int)Session::get('user_id'), 'weekly_report_viewed', "Viewed weekly savings: {$week['label']}");

        $this->render('weekly-reports/savings', [
            'pageTitle'   => 'Weekly Savings Report — ' . APP_NAME,
            'breadcrumbs' => [['label' => 'Weekly Reports', 'url' => APP_URL . '/index.php?page=weekly-savings'], ['label' => 'Savings']],
            'week'        => $week,
            'members'     => $members,
            'summary'     => $summary,
            'weeks'       => $weeks,
        ]);
    }

    // ----------------------------------------------------------------
    // WEEKLY LOAN DISBURSEMENTS
    // ----------------------------------------------------------------
    public function loans(): void
    {
        Session::requireAuth();
        $this->blockOfficeAdmin();

        $weekDate = $_GET['week'] ?? null;
        $week     = $this->model->getWeekBounds($weekDate);
        $loans    = $this->model->getWeeklyLoanDisbursements($week['start'], $week['end']);
        $summary  = $this->model->getWeeklyLoanDisbursementSummary($week['start'], $week['end']);
        $weeks    = $this->model->getAvailableWeeks(20);

        $this->render('weekly-reports/loans', [
            'pageTitle'   => 'Weekly Loan Disbursements — ' . APP_NAME,
            'breadcrumbs' => [['label' => 'Weekly Reports'], ['label' => 'Loans']],
            'week'        => $week,
            'loans'       => $loans,
            'summary'     => $summary,
            'weeks'       => $weeks,
        ]);
    }

    // ----------------------------------------------------------------
    // WEEKLY LOAN REPAYMENTS
    // ----------------------------------------------------------------
    public function repayments(): void
    {
        Session::requireAuth();
        $this->blockOfficeAdmin();

        $weekDate = $_GET['week'] ?? null;
        $week     = $this->model->getWeekBounds($weekDate);
        $members  = $this->model->getWeeklyLoanRepayments($week['start'], $week['end']);
        $summary  = $this->model->getWeeklyRepaymentSummary($week['start'], $week['end']);
        $weeks    = $this->model->getAvailableWeeks(20);

        $this->render('weekly-reports/repayments', [
            'pageTitle'   => 'Weekly Loan Repayments — ' . APP_NAME,
            'breadcrumbs' => [['label' => 'Weekly Reports'], ['label' => 'Repayments']],
            'week'        => $week,
            'members'     => $members,
            'summary'     => $summary,
            'weeks'       => $weeks,
        ]);
    }

    // ----------------------------------------------------------------
    // OVERDUE LOANS
    // ----------------------------------------------------------------
    public function overdue(): void
    {
        Session::requireAuth();
        $this->blockOfficeAdmin();

        $loans = $this->model->getOverdueLoans();

        $this->render('weekly-reports/overdue', [
            'pageTitle'   => 'Overdue Loans — ' . APP_NAME,
            'breadcrumbs' => [['label' => 'Weekly Reports'], ['label' => 'Overdue']],
            'loans'       => $loans,
        ]);
    }
}
