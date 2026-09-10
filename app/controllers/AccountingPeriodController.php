<?php
/**
 * AccountingPeriodController — Accounting Period Management (Stage 20)
 *
 * View access: admin/treasurer/viewer (matches the read-access pattern
 * used elsewhere in this app, e.g. SavingsAccountController). Create/
 * close/reopen remain admin-only, matching this application's existing
 * convention that every destructive/control-bearing accounting action
 * (loan/savings/repayment deletion, withdrawal policy changes) is
 * admin-gated -- Stage 24 will formalize a dedicated permission set (see
 * results/stage20_accounting_period_evidence/ for the specific
 * capabilities this stage recommends: accounting_period.view/create/
 * close/reopen/audit) rather than this stage inventing a broad new
 * ad-hoc role.
 */
class AccountingPeriodController extends Controller
{
    private AccountingPeriodModel $model;

    public function __construct()
    {
        Session::requireAuth();

        // System Administrator role refinement (SA-1, 2026-09): view access
        // widened to include system_admin -- Accounting Periods (open/
        // close/reopen the period a transaction can post into) is system
        // configuration per that stage's audit. requireWriteAccess() below
        // is widened the same way.
        if (!Session::hasRole(['admin', 'treasurer', 'viewer', 'chairman', 'system_admin'])) {
            http_response_code(403);
            die('Access denied.');
        }

        $this->model = new AccountingPeriodModel();
    }

    /** Chairman role-refinement (2026-09): narrowed from admin/chairman to
     *  admin/treasurer -- Chairman keeps view-only access (constructor
     *  gate, unchanged), Treasurer gains create/close/reopen as the
     *  designated "Financial Operations & Accounting" role. */
    private function requireWriteAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer', 'system_admin'])) {
            http_response_code(403);
            die('Access denied. Admin, Treasurer, or System Administrator privileges required for accounting period management.');
        }
    }

    /**
     * List all accounting periods, with per-period journal totals so the
     * list itself already answers "how much activity happened here"
     * without opening the detail page (§10).
     */
    public function index(): void
    {
        $periods = $this->model->getAll();
        foreach ($periods as &$p) {
            $balance = $this->model->getPeriodJournalBalance((int)$p['id']);
            $p['journal_entries'] = $balance['entry_count'];
            $p['total_debit']     = $balance['total_debit'];
            $p['total_credit']    = $balance['total_credit'];
        }
        unset($p);

        $this->render('accounting-periods/index', [
            'pageTitle' => 'Accounting Periods',
            'periods'   => $periods,
            'canWrite'  => Session::hasRole(['admin', 'treasurer', 'system_admin']), // matches requireWriteAccess() exactly (SA-1 role-refinement)
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    /** Period detail page — Overview/Activity/Journals/Trial Balance/General Ledger/Income Statement/Balance Sheet/Audit (§11-19). */
    public function view(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $period = $this->model->getPeriodWithYear($id);
        if (!$period) {
            http_response_code(404);
            die('Accounting period not found.');
        }

        $tab = $_GET['tab'] ?? 'overview';
        $allowedTabs = ['overview', 'activity', 'journals', 'trial-balance', 'general-ledger', 'income-statement', 'balance-sheet', 'audit'];
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'overview';
        }

        $data = [
            'pageTitle' => 'Accounting Period — ' . $period['name'],
            'period'    => $period,
            'tab'       => $tab,
            'summary'   => $this->model->getPeriodSummary($id),
            'canWrite'  => Session::hasRole(['admin', 'treasurer', 'system_admin']), // matches requireWriteAccess() exactly (SA-1 role-refinement)
            'csrfToken' => $this->getCsrf(),
        ];

        switch ($tab) {
            case 'activity':
                $data['activity'] = $this->model->getPeriodActivity($id, ['module' => $_GET['module'] ?? '']);
                break;
            case 'journals':
                $data['journals'] = $this->model->getPeriodJournalActivity($id, ['module' => $_GET['module'] ?? '']);
                break;
            case 'trial-balance':
                $data['trialBalance'] = (new AccountingReportModel())->trialBalance(['accounting_period_id' => $id]);
                break;
            case 'general-ledger':
                $accountId = (int)($_GET['account_id'] ?? 0);
                $data['accounts'] = $this->db()->query("SELECT id, code, name FROM accounts ORDER BY code")->fetchAll();
                if ($accountId > 0) {
                    $data['ledger'] = (new AccountingReportModel())->generalLedgerForAccount($accountId, ['accounting_period_id' => $id]);
                    $data['selectedAccountId'] = $accountId;
                }
                break;
            case 'income-statement':
                $data['incomeStatement'] = (new AccountingReportModel())->incomeStatement(['accounting_period_id' => $id]);
                break;
            case 'balance-sheet':
                $data['balanceSheet'] = (new AccountingReportModel())->balanceSheet(['financial_year_id' => $period['financial_year_id']]);
                break;
            case 'audit':
                $data['audit'] = $this->model->getPeriodAuditActivity($period);
                break;
        }

        $this->render('accounting-periods/view', $data);
    }

    /** Show create form */
    public function create(): void
    {
        $this->requireWriteAccess();
        $financialYears = $this->model->getFinancialYearsForPeriods();

        $this->render('accounting-periods/form', [
            'pageTitle'      => 'Create Accounting Period',
            'financialYears' => $financialYears,
            'period'         => null,
            'csrfToken'      => $this->getCsrf(),
        ]);
    }

    /** Store new period */
    public function store(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=accounting-period-create');
            exit;
        }

        try {
            $input = [
                'financial_year_id' => (int)$_POST['financial_year_id'],
                'name'              => trim($_POST['name']),
                'start_date'        => $_POST['start_date'],
                'end_date'          => $_POST['end_date'],
                'status'            => $_POST['status'] ?? 'open',
                'created_by'        => (int)Session::get('user_id'),
            ];

            if (empty($input['name'])) {
                throw new InvalidArgumentException('Period name is required.');
            }
            if (empty($input['start_date']) || empty($input['end_date'])) {
                throw new InvalidArgumentException('Start and end dates are required.');
            }
            if ($input['start_date'] > $input['end_date']) {
                throw new InvalidArgumentException('Start date must be before end date.');
            }

            $periodId = $this->model->createPeriod($input);

            if ($periodId) {
                Session::flash('success', 'Accounting period created successfully.');
                header('Location: ' . APP_URL . '/index.php?page=accounting-periods');
                exit;
            } else {
                throw new RuntimeException('Failed to create accounting period.');
            }
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
            header('Location: ' . APP_URL . '/index.php?page=accounting-period-create');
            exit;
        }
    }

    /** Confirmation page for closing a period — requires a reason (§21). */
    public function closeConfirm(): void
    {
        $this->requireWriteAccess();
        $id = (int)($_GET['id'] ?? 0);
        $period = $this->model->getPeriodWithYear($id);
        if (!$period) {
            http_response_code(404);
            die('Accounting period not found.');
        }

        $this->render('accounting-periods/close-confirm', [
            'pageTitle' => 'Close Accounting Period',
            'period'    => $period,
            'summary'   => $this->model->getPeriodSummary($id),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    /** Close a period */
    public function close(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=accounting-periods');
            exit;
        }

        $periodId = (int)$_POST['period_id'];
        try {
            $userId = (int)Session::get('user_id');
            $reason = trim($_POST['reason'] ?? '');

            $this->model->closePeriod($periodId, $userId, $reason);
            Session::flash('success', 'Accounting period closed successfully.');
            header('Location: ' . APP_URL . '/index.php?page=accounting-period-view&id=' . $periodId);
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
            header('Location: ' . APP_URL . '/index.php?page=accounting-period-close-confirm&id=' . $periodId);
        }
        exit;
    }

    /** Confirmation page for reopening a closed period — requires a reason (§9, §23). */
    public function reopenConfirm(): void
    {
        $this->requireWriteAccess();
        $id = (int)($_GET['id'] ?? 0);
        $period = $this->model->getPeriodWithYear($id);
        if (!$period) {
            http_response_code(404);
            die('Accounting period not found.');
        }

        $this->render('accounting-periods/reopen-confirm', [
            'pageTitle' => 'Reopen Accounting Period',
            'period'    => $period,
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    /** Reopen a closed period */
    public function reopen(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=accounting-periods');
            exit;
        }

        $periodId = (int)$_POST['period_id'];
        try {
            $userId = (int)Session::get('user_id');
            $reason = trim($_POST['reason'] ?? '');

            $this->model->reopenPeriod($periodId, $userId, $reason);
            Session::flash('success', 'Accounting period reopened successfully.');
            header('Location: ' . APP_URL . '/index.php?page=accounting-period-view&id=' . $periodId);
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
            header('Location: ' . APP_URL . '/index.php?page=accounting-period-reopen-confirm&id=' . $periodId);
        }
        exit;
    }

    private function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }

    private function getCsrf(): string
    {
        if (!Session::has('csrf_token')) {
            Session::set('csrf_token', bin2hex(random_bytes(32)));
        }
        return Session::get('csrf_token');
    }

    private function verifyCsrf(string $token): bool
    {
        $stored = Session::get('csrf_token', '');
        Session::set('csrf_token', bin2hex(random_bytes(32)));
        return hash_equals($stored, $token);
    }
}
