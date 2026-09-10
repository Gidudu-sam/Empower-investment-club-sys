<?php
/**
 * FinancialYearController — canonical Financial Years workflow (view,
 * create, edit, activate, close, reopen). Stage 2 (Financial Years
 * Consolidation) added create/edit/activate here so this is the single
 * authoritative implementation; SettingsController's equivalent actions now
 * delegate/redirect here instead of running their own weaker logic.
 */
class FinancialYearController extends Controller
{
    private FinancialYearModel $model;

    public function __construct()
    {
        Session::requireAuth();
        // System Administrator role refinement (SA-1, 2026-09): view access
        // widened to include system_admin -- Financial Year is a system
        // configuration area per that stage's audit, not an ordinary
        // financial transaction. requireWriteAccess() below is widened the
        // same way.
        if (!Session::hasRole(['admin', 'treasurer', 'viewer', 'chairman', 'system_admin'])) {
            http_response_code(403);
            die('Access denied.');
        }
        $this->model = new FinancialYearModel();
    }

    /** Chairman role-refinement (2026-09): narrowed from admin/chairman to
     *  admin/treasurer -- Chairman keeps view-only access (constructor
     *  gate, unchanged), Treasurer gains create/edit/activate/close/reopen
     *  as the designated "Financial Operations & Accounting" role. */
    private function requireWriteAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer', 'system_admin'])) {
            http_response_code(403);
            die('Access denied. Admin, Treasurer, or System Administrator privileges required for financial year management.');
        }
    }

    public function index(): void
    {
        $years = $this->model->getAll();
        foreach ($years as &$y) {
            $summary = $this->model->getYearSummary((int)$y['id']);
            $y['journal_entries'] = $summary['journal_entries'];
            $y['total_debit']     = $summary['total_debit'];
            $y['total_credit']    = $summary['total_credit'];
        }
        unset($y);

        $this->render('financial-years/index', [
            'pageTitle' => 'Financial Years',
            'years'     => $years,
            'canWrite'  => Session::hasRole(['admin', 'treasurer']), // matches requireWriteAccess() exactly (Chairman role-refinement)
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function view(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $year = $this->model->find($id);
        if (!$year) {
            http_response_code(404);
            die('Financial year not found.');
        }

        $this->render('financial-years/view', [
            'pageTitle' => 'Financial Year — ' . $year['name'],
            'year'      => $year,
            'periods'   => $this->model->getPeriodsForYear($id),
            'summary'   => $this->model->getYearSummary($id),
            'audit'     => $this->model->getAuditActivity($year),
            'canWrite'  => Session::hasRole(['admin', 'treasurer']), // matches requireWriteAccess() exactly (Chairman role-refinement)
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function create(): void
    {
        $this->requireWriteAccess();
        $this->render('financial-years/form', [
            'pageTitle' => 'Create Financial Year',
            'formMode'  => 'create',
            'year'      => null,
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function store(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=financial-year-create');
            exit;
        }

        try {
            $id = $this->model->createYear(
                $_POST['name'] ?? '',
                $_POST['start_date'] ?? '',
                $_POST['end_date'] ?? '',
                (int)Session::get('user_id')
            );
            Session::flash('success', 'Financial year created.');
            header('Location: ' . APP_URL . '/index.php?page=financial-year-view&id=' . $id);
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            header('Location: ' . APP_URL . '/index.php?page=financial-year-create');
        }
        exit;
    }

    public function edit(): void
    {
        $this->requireWriteAccess();
        $id = (int)($_GET['id'] ?? 0);
        $year = $this->model->find($id);
        if (!$year) {
            http_response_code(404);
            die('Financial year not found.');
        }

        $this->render('financial-years/form', [
            'pageTitle' => 'Edit Financial Year',
            'formMode'  => 'edit',
            'year'      => $year,
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function update(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=financial-years');
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        try {
            $this->model->updateYear(
                $id,
                $_POST['name'] ?? '',
                $_POST['start_date'] ?? '',
                $_POST['end_date'] ?? '',
                (int)Session::get('user_id')
            );
            Session::flash('success', 'Financial year updated.');
            header('Location: ' . APP_URL . '/index.php?page=financial-year-view&id=' . $id);
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            header('Location: ' . APP_URL . '/index.php?page=financial-year-edit&id=' . $id);
        }
        exit;
    }

    public function activateConfirm(): void
    {
        $this->requireWriteAccess();
        $id = (int)($_GET['id'] ?? 0);
        $year = $this->model->find($id);
        if (!$year) {
            http_response_code(404);
            die('Financial year not found.');
        }

        $currentActive = null;
        foreach ($this->model->getAll() as $y) {
            if ($y['status'] === 'active' && (int)$y['id'] !== $id) { $currentActive = $y; break; }
        }

        $this->render('financial-years/activate-confirm', [
            'pageTitle'      => 'Activate Financial Year',
            'year'           => $year,
            'currentActive'  => $currentActive,
            'csrfToken'      => $this->getCsrf(),
        ]);
    }

    public function activate(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=financial-years');
            exit;
        }

        $id = (int)($_POST['year_id'] ?? 0);
        try {
            $this->model->activateYear($id, (int)Session::get('user_id'));
            Session::flash('success', 'Financial year activated.');
            header('Location: ' . APP_URL . '/index.php?page=financial-year-view&id=' . $id);
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            header('Location: ' . APP_URL . '/index.php?page=financial-year-view&id=' . $id);
        }
        exit;
    }

    public function closeConfirm(): void
    {
        $this->requireWriteAccess();
        $id = (int)($_GET['id'] ?? 0);
        $year = $this->model->find($id);
        if (!$year) {
            http_response_code(404);
            die('Financial year not found.');
        }

        $this->render('financial-years/close-confirm', [
            'pageTitle' => 'Close Financial Year',
            'year'      => $year,
            'summary'   => $this->model->getYearSummary($id),
            'openPeriodCount' => count(array_filter($this->model->getPeriodsForYear($id), fn($p) => $p['status'] === 'open')),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function close(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=financial-years');
            exit;
        }

        $yearId = (int)$_POST['year_id'];
        try {
            $this->model->closeYear($yearId, (int)Session::get('user_id'), trim($_POST['reason'] ?? ''));
            Session::flash('success', 'Financial year closed successfully.');
            header('Location: ' . APP_URL . '/index.php?page=financial-year-view&id=' . $yearId);
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
            header('Location: ' . APP_URL . '/index.php?page=financial-year-close-confirm&id=' . $yearId);
        }
        exit;
    }

    public function reopenConfirm(): void
    {
        $this->requireWriteAccess();
        $id = (int)($_GET['id'] ?? 0);
        $year = $this->model->find($id);
        if (!$year) {
            http_response_code(404);
            die('Financial year not found.');
        }

        $this->render('financial-years/reopen-confirm', [
            'pageTitle' => 'Reopen Financial Year',
            'year'      => $year,
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function reopen(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=financial-years');
            exit;
        }

        $yearId = (int)$_POST['year_id'];
        try {
            $this->model->reopenYear($yearId, (int)Session::get('user_id'), trim($_POST['reason'] ?? ''));
            Session::flash('success', 'Financial year reopened successfully.');
            header('Location: ' . APP_URL . '/index.php?page=financial-year-view&id=' . $yearId);
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
            header('Location: ' . APP_URL . '/index.php?page=financial-year-reopen-confirm&id=' . $yearId);
        }
        exit;
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
