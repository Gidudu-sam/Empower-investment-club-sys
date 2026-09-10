<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/ReportModel.php';

/**
 * ReportController — Comprehensive Reports Module
 */
class ReportController extends Controller
{
    private ReportModel $model;

    public function __construct()
    {
        // Stage 1 security remediation: club-wide operational/financial
        // reports had no role gate at all -- any authenticated session,
        // including "member", could view them. Restricted to staff roles.
        // Role-policy stage: office_admin added here (member/savings reports
        // are explicitly part of its workspace) -- but kept OUT of the
        // finance/loan-specific reports below via requireFinancialReportAccess().
        // Loans Officer role-refinement (2026-09): loans_officer is admitted
        // here ONLY for loans()/aging()/repayments() below -- index()/
        // members()/savings()/shares()/withdrawals()/financial() each carry
        // an explicit blockLoansOfficer() call, since those are outside this
        // role's "loan operational reporting" scope. Do not widen this
        // role's intent by removing those per-action checks.
        // Stage 23: Secretary and Vice Chairman both need read-only report
        // access -- Secretary per the brief's explicit "Records/Oversight"
        // requirement, Vice Chairman per its broader deputy-oversight
        // mandate. Neither role gains any write capability anywhere in
        // this controller (it has none -- every method here is read-only).
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'treasurer', 'cashier', 'viewer', 'chairman', 'office_admin', 'loans_officer', 'secretary', 'vice_chairman'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view reports.');
        }

        $this->model = new ReportModel();
    }

    /** Loan/financial/withdrawal/aging/repayment reports stay out of
     *  Office Administrator's workspace -- only Member and Savings reports
     *  were requested for that role; everything else here keeps the
     *  original, narrower report-viewing tier.
     *  Loans Officer role-refinement (2026-09): loans_officer is admitted
     *  here too -- it governs loans()/aging()/repayments() (wanted) as well
     *  as shares()/withdrawals()/financial() (NOT wanted for loans_officer,
     *  each individually blocked via blockLoansOfficer() below). */
    /** Stage 23: Secretary and Vice Chairman added -- both need loan/
     *  financial/withdrawal/aging/repayment reports per the brief's
     *  explicit "Financial/Operational Oversight" navigation requirement. */
    private function requireFinancialReportAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer', 'cashier', 'viewer', 'chairman', 'loans_officer', 'secretary', 'vice_chairman'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view this report.');
        }
    }

    /** Loans Officer role-refinement (2026-09): confines loans_officer to
     *  exactly Loan Report / Loan Aging / Loan Repayment Report -- called
     *  from every OTHER report action in this controller so widening the
     *  two gates above to admit loans_officer doesn't incidentally expose
     *  Member/Savings/Share/Withdrawal/Financial-Summary reports to it. */
    private function blockLoansOfficer(): void
    {
        if (Session::hasRole(['loans_officer'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view this report.');
        }
    }

    // ----------------------------------------------------------------
    // REPORTS DASHBOARD
    // ----------------------------------------------------------------
    public function index(): void
    {
        Session::requireAuth();
        $this->blockLoansOfficer();

        $stats     = $this->model->getDashboardStats();
        $chartData = $this->model->getChartData();

        $this->model->log((int)Session::get('user_id'), 'report_viewed', 'Viewed reports dashboard');

        $this->render('reports/index', [
            'pageTitle'   => 'Reports Dashboard — ' . APP_NAME,
            'breadcrumbs' => [['label' => 'Reports', 'url' => '']],
            'stats'       => $stats,
            'chartData'   => $chartData,
        ]);
    }

    // ----------------------------------------------------------------
    // MEMBER REPORTS
    // ----------------------------------------------------------------
    public function members(): void
    {
        Session::requireAuth();
        $this->blockLoansOfficer();

        $report = $this->model->getMemberReport();

        $this->model->log((int)Session::get('user_id'), 'report_generated', 'Generated member report');

        $this->render('reports/members', [
            'pageTitle'   => 'Member Reports — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Reports', 'url' => APP_URL . '/index.php?page=reports'],
                ['label' => 'Members', 'url' => ''],
            ],
            'report' => $report,
        ]);
    }

    // ----------------------------------------------------------------
    // SAVINGS REPORTS
    // ----------------------------------------------------------------
    public function savings(): void
    {
        Session::requireAuth();
        $this->blockLoansOfficer();

        $type     = $_GET['type'] ?? 'monthly';
        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo   = $_GET['date_to'] ?? '';
        $year     = (int)($_GET['year'] ?? date('Y'));
        $month    = (int)($_GET['month'] ?? date('n'));

        $report = $this->model->getSavingsReport($type, $dateFrom, $dateTo, $year, $month);

        $this->model->log((int)Session::get('user_id'), 'report_generated', "Generated {$type} savings report");

        $this->render('reports/savings', [
            'pageTitle'   => 'Savings Reports — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Reports', 'url' => APP_URL . '/index.php?page=reports'],
                ['label' => 'Savings', 'url' => ''],
            ],
            'report'   => $report,
            'type'     => $type,
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
            'year'     => $year,
            'month'    => $month,
        ]);
    }

    // ----------------------------------------------------------------
    // LOAN REPORTS
    // ----------------------------------------------------------------
    public function loans(): void
    {
        Session::requireAuth();
        $this->requireFinancialReportAccess();

        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo   = $_GET['date_to'] ?? '';

        $report = $this->model->getLoanReport($dateFrom, $dateTo);

        $this->model->log((int)Session::get('user_id'), 'report_generated', 'Generated loan report');

        $this->render('reports/loans', [
            'pageTitle'   => 'Loan Reports — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Reports', 'url' => APP_URL . '/index.php?page=reports'],
                ['label' => 'Loans', 'url' => ''],
            ],
            'report'   => $report,
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
        ]);
    }

    // ----------------------------------------------------------------
    // LOAN AGING REPORT
    // ----------------------------------------------------------------
    public function aging(): void
    {
        Session::requireAuth();
        $this->requireFinancialReportAccess();

        $agingData = $this->model->getLoanAgingReport();

        $this->model->log((int)Session::get('user_id'), 'report_generated', 'Generated loan aging report');

        $this->render('loans/loan-aging', [
            'pageTitle' => 'Loan Aging Report — ' . APP_NAME,
            'agingData' => $agingData,
        ]);
    }

    // ----------------------------------------------------------------
    // LOAN REPAYMENT REPORTS
    // ----------------------------------------------------------------
    public function repayments(): void
    {
        Session::requireAuth();
        $this->requireFinancialReportAccess();

        $type     = $_GET['type'] ?? 'monthly';
        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo   = $_GET['date_to'] ?? '';
        $year     = (int)($_GET['year'] ?? date('Y'));
        $month    = (int)($_GET['month'] ?? date('n'));

        $report = $this->model->getRepaymentReport($type, $dateFrom, $dateTo, $year, $month);

        $this->model->log((int)Session::get('user_id'), 'report_generated', "Generated {$type} repayment report");

        $this->render('reports/repayments', [
            'pageTitle'   => 'Loan Repayment Reports — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Reports', 'url' => APP_URL . '/index.php?page=reports'],
                ['label' => 'Repayments', 'url' => ''],
            ],
            'report'   => $report,
            'type'     => $type,
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
            'year'     => $year,
            'month'    => $month,
        ]);
    }

    // ----------------------------------------------------------------
    // SHARE REPORTS
    // ----------------------------------------------------------------
    public function shares(): void
    {
        Session::requireAuth();
        $this->requireFinancialReportAccess();
        $this->blockLoansOfficer();

        $report = $this->model->getShareReport();

        $this->model->log((int)Session::get('user_id'), 'report_generated', 'Generated share report');

        $this->render('reports/shares', [
            'pageTitle'   => 'Share Reports — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Reports', 'url' => APP_URL . '/index.php?page=reports'],
                ['label' => 'Shares', 'url' => ''],
            ],
            'report' => $report,
        ]);
    }

    // ----------------------------------------------------------------
    // WITHDRAWAL REPORTS
    // ----------------------------------------------------------------
    public function withdrawals(): void
    {
        Session::requireAuth();
        $this->requireFinancialReportAccess();
        $this->blockLoansOfficer();

        $year = (int)($_GET['year'] ?? date('Y'));

        $report = $this->model->getWithdrawalReport($year);

        $this->model->log((int)Session::get('user_id'), 'report_generated', "Generated {$year} withdrawal report");

        $this->render('reports/withdrawals', [
            'pageTitle'   => 'Withdrawal Reports — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Reports', 'url' => APP_URL . '/index.php?page=reports'],
                ['label' => 'Withdrawals', 'url' => ''],
            ],
            'report' => $report,
            'year'   => $year,
        ]);
    }

    // ----------------------------------------------------------------
    // FINANCIAL SUMMARY
    // ----------------------------------------------------------------
    public function financial(): void
    {
        Session::requireAuth();
        $this->requireFinancialReportAccess();
        $this->blockLoansOfficer();

        $report = $this->model->getFinancialSummary();

        $this->model->log((int)Session::get('user_id'), 'report_generated', 'Generated financial summary');

        $this->render('reports/financial', [
            'pageTitle'   => 'Financial Summary — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Reports', 'url' => APP_URL . '/index.php?page=reports'],
                ['label' => 'Financial Summary', 'url' => ''],
            ],
            'report' => $report,
        ]);
    }
}
