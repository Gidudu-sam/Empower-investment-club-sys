<?php
/**
 * OpeningBalanceController — Opening Balance maker-checker workflow.
 *
 * Prepare/submit/post: admin or treasurer. Approve/reject: admin or
 * chairman, and never the batch's own preparer (enforced in
 * OpeningBalanceBatchModel).
 *
 * Stage 7A fix: the constructor previously gated the ENTIRE controller
 * to admin/treasurer only, which meant chairman was rejected before
 * ever reaching approve()/reject() -- those methods' own admin/chairman
 * check was unreachable dead code. Split into a view-tier gate (admin,
 * treasurer, chairman) covering index()/view(), and an explicit
 * write-tier gate on create/store/submit/post specifically, so chairman
 * gains exactly view+approve+reject and nothing else -- no create,
 * edit, submit, or post authority, matching the "do not grant Chairman
 * unrelated write access" rule.
 */
class OpeningBalanceController extends Controller
{
    private OpeningBalanceBatchModel $model;

    public function __construct()
    {
        Session::requireAuth();

        // Stage 23: Vice Chairman added as deputy approver below, so needs
        // view access too. Secretary deliberately excluded -- out of
        // Secretary's granted Stage 23 scope (one-time historical
        // accounting entries, not a governance document workflow).
        if (!Session::hasRole(['admin', 'treasurer', 'chairman', 'vice_chairman'])) {
            http_response_code(403);
            die('Access denied. Admin, Treasurer, Chairman, or Vice Chairman privileges required for opening balances.');
        }

        $this->model = new OpeningBalanceBatchModel();
    }

    /** Prepare/submit/post — admin/treasurer only, matching the model's own docblock. Chairman does not get this. */
    private function requireWriteAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer'])) {
            http_response_code(403);
            die('Access denied. Admin or Treasurer privileges required for this action.');
        }
    }

    public function index(): void
    {
        $batches = $this->model->getAll();

        $this->render('opening-balances/index', [
            'pageTitle' => 'Opening Balances',
            'batches' => $batches,
            'canWrite' => Session::hasRole(['admin', 'treasurer']),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function create(): void
    {
        $this->requireWriteAccess();
        $periods = $this->db()->query("
            SELECT ap.*, fy.name AS financial_year_name
            FROM accounting_periods ap
            JOIN financial_years fy ON fy.id = ap.financial_year_id
            WHERE ap.status = 'open'
            ORDER BY ap.start_date DESC
        ")->fetchAll();

        $accounts = (new AccountModel())->activeAccounts();

        $this->render('opening-balances/form', [
            'pageTitle' => 'Create Opening Balance',
            'periods' => $periods,
            'accounts' => $accounts,
            'batch' => null,
            'lines' => [],
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function store(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=opening-balance-create');
            exit;
        }

        try {
            $periodId = (int)($_POST['accounting_period_id'] ?? 0);
            $period = (new AccountingPeriodModel())->find($periodId);
            if (!$period) {
                throw new InvalidArgumentException('Please select a valid accounting period.');
            }

            $data = [
                'financial_year_id'    => (int)$period['financial_year_id'],
                'accounting_period_id' => $periodId,
                'as_of_date'           => $_POST['as_of_date'] ?? '',
            ];

            $lines = $this->parseLines($_POST);

            $userId = (int)Session::get('user_id');
            $batchId = $this->model->createDraft($data, $lines, $userId);

            Session::flash('success', 'Opening balance batch created as draft.');
            header('Location: ' . APP_URL . '/index.php?page=opening-balance-view&id=' . $batchId);
            exit;
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
            header('Location: ' . APP_URL . '/index.php?page=opening-balance-create');
            exit;
        }
    }

    public function view(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $batch = $this->model->findWithLines($id);
        if (!$batch) {
            http_response_code(404);
            die('Opening balance batch not found.');
        }

        $this->render('opening-balances/view', [
            'pageTitle' => 'Opening Balance ' . $batch['batch_number'],
            'batch' => $batch,
            'auditTrail' => $this->model->auditTrail($id),
            'currentUserId' => (int)Session::get('user_id'),
            'isAdmin' => Session::hasRole(['admin', 'chairman', 'vice_chairman']),
            'canWrite' => Session::hasRole(['admin', 'treasurer']),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function submit(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=opening-balances');
            exit;
        }

        $id = (int)($_POST['batch_id'] ?? 0);
        try {
            $this->model->submit($id, (int)Session::get('user_id'));
            Session::flash('success', 'Opening balance batch submitted for approval.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }

        header('Location: ' . APP_URL . '/index.php?page=opening-balance-view&id=' . $id);
        exit;
    }

    /** Stage 23: Vice Chairman added as deputy approver. */
    public function approve(): void
    {
        if (!Session::hasRole(['admin', 'chairman', 'vice_chairman'])) {
            http_response_code(403);
            die('Access denied. Admin, Chairman, or Vice Chairman privileges required to approve opening balances.');
        }
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=opening-balances');
            exit;
        }

        $id = (int)($_POST['batch_id'] ?? 0);
        try {
            $this->model->approve($id, (int)Session::get('user_id'));
            Session::flash('success', 'Opening balance batch approved.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }

        header('Location: ' . APP_URL . '/index.php?page=opening-balance-view&id=' . $id);
        exit;
    }

    /** Stage 23: Vice Chairman added as deputy approver. */
    public function reject(): void
    {
        if (!Session::hasRole(['admin', 'chairman', 'vice_chairman'])) {
            http_response_code(403);
            die('Access denied. Admin, Chairman, or Vice Chairman privileges required to reject opening balances.');
        }
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=opening-balances');
            exit;
        }

        $id = (int)($_POST['batch_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        try {
            $this->model->reject($id, (int)Session::get('user_id'), $reason);
            Session::flash('success', 'Opening balance batch rejected.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }

        header('Location: ' . APP_URL . '/index.php?page=opening-balance-view&id=' . $id);
        exit;
    }

    public function post(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=opening-balances');
            exit;
        }

        $id = (int)($_POST['batch_id'] ?? 0);
        try {
            $result = $this->model->post($id, (int)Session::get('user_id'));
            Session::flash('success', 'Opening balance batch posted as journal entry ' . ($result['entry_number'] ?? '') . '.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }

        header('Location: ' . APP_URL . '/index.php?page=opening-balance-view&id=' . $id);
        exit;
    }

    private function parseLines(array $post): array
    {
        $accountIds   = $post['line_account_id'] ?? [];
        $debits       = $post['line_debit'] ?? [];
        $credits      = $post['line_credit'] ?? [];
        $descriptions = $post['line_description'] ?? [];

        $lines = [];
        foreach ($accountIds as $i => $accountId) {
            if (empty($accountId)) {
                continue;
            }
            $lines[] = [
                'account_id'  => (int)$accountId,
                'debit'       => (float)($debits[$i] ?? 0),
                'credit'      => (float)($credits[$i] ?? 0),
                'description' => trim($descriptions[$i] ?? '') ?: null,
            ];
        }
        return $lines;
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
