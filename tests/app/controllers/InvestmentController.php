<?php
/**
 * InvestmentController — Investments maker-checker workflow + post-placement
 * transactions (income/withdrawal/disposal) + investment type configuration.
 *
 * View: admin/treasurer/viewer. Create/submit/post/transactions: admin or
 * treasurer. Approve/reject (checker) and type management: admin only —
 * matching OpeningBalanceController's precedent exactly. The preparer may
 * never approve their own investment (enforced in InvestmentModel).
 */
class InvestmentController extends Controller
{
    private InvestmentModel $model;
    private InvestmentTypeModel $typeModel;
    private InvestmentTransactionModel $txnModel;

    public function __construct()
    {
        Session::requireAuth();

        // Stage 23: Vice Chairman (deputy approver) and Secretary (approver
        // on this workflow specifically) both need view access before they
        // can reach approve()/reject() below.
        if (!Session::hasRole(['admin', 'treasurer', 'viewer', 'chairman', 'vice_chairman', 'secretary'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view investments.');
        }

        $this->model     = new InvestmentModel();
        $this->typeModel = new InvestmentTypeModel();
        $this->txnModel  = new InvestmentTransactionModel();
    }

    private function requireWriteAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer'])) {
            http_response_code(403);
            die('Access denied. Admin or Treasurer privileges required.');
        }
    }

    /** Stage 23: Vice Chairman added as deputy/alternate approver;
     *  Secretary added as co-approver on this specific workflow -- both
     *  per management's explicit Stage 23 governance decision. Investment
     *  approve()/reject() only change status/audit fields (InvestmentModel
     *  already blocks self-approval independently of role), never post to
     *  the GL directly -- that happens in the separate post() action,
     *  still admin/treasurer only via requireWriteAccess(). */
    private function requireApproverAccess(): void
    {
        if (!Session::hasRole(['admin', 'chairman', 'vice_chairman', 'secretary'])) {
            http_response_code(403);
            die('Access denied. Admin, Chairman, Vice Chairman, or Secretary privileges required to approve or reject investments.');
        }
    }

    // ----------------------------------------------------------------
    // INVESTMENTS
    // ----------------------------------------------------------------

    public function index(): void
    {
        $search   = trim($_GET['search'] ?? '');
        $status   = trim($_GET['status'] ?? '');
        $typeId   = trim($_GET['investment_type_id'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo   = trim($_GET['date_to'] ?? '');

        $investments = $this->model->getAll();

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $investments = array_values(array_filter($investments, function ($i) use ($needle) {
                return str_contains(mb_strtolower($i['investment_number']), $needle)
                    || str_contains(mb_strtolower($i['provider_name'] ?? ''), $needle)
                    || str_contains(mb_strtolower($i['reference'] ?? ''), $needle);
            }));
        }
        if ($status !== '') {
            $investments = array_values(array_filter($investments, fn($i) => $i['status'] === $status));
        }
        if ($typeId !== '') {
            $investments = array_values(array_filter($investments, fn($i) => (int)$i['investment_type_id'] === (int)$typeId));
        }
        if ($dateFrom !== '') {
            $investments = array_values(array_filter($investments, fn($i) => $i['start_date'] >= $dateFrom));
        }
        if ($dateTo !== '') {
            $investments = array_values(array_filter($investments, fn($i) => $i['start_date'] <= $dateTo));
        }

        $this->render('investments/index', [
            'pageTitle'   => 'Investments',
            'investments' => $investments,
            'types'       => $this->typeModel->allWithAccounts(),
            'filters'     => compact('search', 'status', 'typeId', 'dateFrom', 'dateTo'),
            'csrfToken'   => $this->getCsrf(),
        ]);
    }

    public function create(): void
    {
        $this->requireWriteAccess();

        $this->render('investments/form', [
            'pageTitle' => 'Record Investment',
            'types'     => $this->typeModel->activeTypes(),
            'accounts'  => (new AccountModel())->activeAccounts(),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function store(): void
    {
        $this->requireWriteAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=investment-create');
            exit;
        }

        try {
            $id = $this->model->createDraft([
                'investment_type_id'    => $_POST['investment_type_id'] ?? null,
                'reference'             => trim($_POST['reference'] ?? ''),
                'provider_name'         => trim($_POST['provider_name'] ?? ''),
                'principal_amount'      => $_POST['principal_amount'] ?? null,
                'start_date'            => $_POST['start_date'] ?? null,
                'maturity_date'         => $_POST['maturity_date'] ?: null,
                'expected_rate'         => $_POST['expected_rate'] !== '' ? $_POST['expected_rate'] : null,
                'investment_account_id' => $_POST['investment_account_id'] ?: null,
                'funding_account_id'    => $_POST['funding_account_id'] ?? null,
                'notes'                 => trim($_POST['notes'] ?? ''),
            ], (int)Session::get('user_id'));

            Session::flash('success', 'Investment created as draft.');
            header('Location: ' . APP_URL . '/index.php?page=investment-view&id=' . $id);
            exit;
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
            header('Location: ' . APP_URL . '/index.php?page=investment-create');
            exit;
        }
    }

    public function view(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $investment = $this->model->findWithDetails($id);
        if (!$investment) {
            http_response_code(404);
            die('Investment not found.');
        }

        $this->render('investments/view', [
            'pageTitle'      => 'Investment ' . $investment['investment_number'],
            'investment'     => $investment,
            // Recent-first for this plain transaction list (transactions()
            // itself stays chronological -- it's not otherwise reused by a
            // formal statement here, but keeping the model method itself
            // unopinionated matches the same convention used for savings
            // and loan repayments).
            'transactions'   => array_reverse($this->model->transactions($id)),
            'auditTrail'     => $this->model->auditTrail($id),
            'currentUserId'  => (int)Session::get('user_id'),
            // Stage 23: matches requireApproverAccess() exactly, so the
            // Approve/Reject buttons show for every role actually
            // authorized to click them.
            'isAdmin'        => Session::hasRole(['admin', 'chairman', 'vice_chairman', 'secretary']),
            'canWrite'       => Session::hasRole(['admin', 'treasurer']),
            'accounts'       => (new AccountModel())->activeAccounts(),
            'csrfToken'      => $this->getCsrf(),
        ]);
    }

    public function submit(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=investments');
            exit;
        }

        $id = (int)($_POST['investment_id'] ?? 0);
        try {
            $this->model->submit($id, (int)Session::get('user_id'));
            Session::flash('success', 'Investment submitted for approval.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=investment-view&id=' . $id);
        exit;
    }

    public function approve(): void
    {
        $this->requireApproverAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=investments');
            exit;
        }

        $id = (int)($_POST['investment_id'] ?? 0);
        try {
            $this->model->approve($id, (int)Session::get('user_id'));
            Session::flash('success', 'Investment approved.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=investment-view&id=' . $id);
        exit;
    }

    public function reject(): void
    {
        $this->requireApproverAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=investments');
            exit;
        }

        $id = (int)($_POST['investment_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        try {
            $this->model->reject($id, (int)Session::get('user_id'), $reason);
            Session::flash('success', 'Investment rejected.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=investment-view&id=' . $id);
        exit;
    }

    public function post(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=investments');
            exit;
        }

        $id = (int)($_POST['investment_id'] ?? 0);
        try {
            $result = $this->model->post($id, (int)Session::get('user_id'));
            Session::flash('success', 'Investment posted as journal entry ' . ($result['entry_number'] ?? '') . '.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=investment-view&id=' . $id);
        exit;
    }

    // ----------------------------------------------------------------
    // INVESTMENT TRANSACTIONS (income / withdrawal / disposal)
    // ----------------------------------------------------------------

    public function transactionCreate(): void
    {
        $this->requireWriteAccess();

        $investmentId = (int)($_GET['investment_id'] ?? 0);
        $investment = $this->model->findWithDetails($investmentId);
        if (!$investment) {
            http_response_code(404);
            die('Investment not found.');
        }

        $this->render('investments/transaction-form', [
            'pageTitle'  => 'Record Investment Transaction',
            'investment' => $investment,
            'accounts'   => (new AccountModel())->activeAccounts(),
            'csrfToken'  => $this->getCsrf(),
        ]);
    }

    public function transactionStore(): void
    {
        $this->requireWriteAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=investments');
            exit;
        }

        $investmentId = (int)($_POST['investment_id'] ?? 0);
        try {
            $id = $this->txnModel->createDraft([
                'investment_id'      => $investmentId,
                'transaction_type'   => $_POST['transaction_type'] ?? null,
                'amount'             => $_POST['amount'] ?? null,
                'transaction_date'   => $_POST['transaction_date'] ?? null,
                'description'        => trim($_POST['description'] ?? ''),
                'funding_account_id' => $_POST['funding_account_id'] ?? null,
            ], (int)Session::get('user_id'));

            // Simple draft -> posted workflow: post immediately after create,
            // per the approved design (no separate approval gate for v1).
            $this->txnModel->post($id, (int)Session::get('user_id'));

            Session::flash('success', 'Investment transaction recorded and posted.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=investment-view&id=' . $investmentId);
        exit;
    }

    // ----------------------------------------------------------------
    // INVESTMENT TYPES (admin-only configuration)
    // ----------------------------------------------------------------

    public function types(): void
    {
        if (!Session::hasRole(['admin'])) {
            http_response_code(403);
            die('Access denied. Admin privileges required to manage investment types.');
        }

        $this->render('investments/types', [
            'pageTitle' => 'Investment Types',
            'types'     => $this->typeModel->allWithAccounts(),
            'accounts'  => (new AccountModel())->activeAccounts(),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function typeStore(): void
    {
        if (!Session::hasRole(['admin'])) {
            http_response_code(403);
            die('Access denied. Admin privileges required to manage investment types.');
        }
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=investment-types');
            exit;
        }

        try {
            if (empty($_POST['type_name'])) {
                throw new InvalidArgumentException('Type name is required.');
            }
            if (empty($_POST['asset_gl_account_id']) || empty($_POST['income_gl_account_id'])) {
                throw new InvalidArgumentException('Asset and income GL accounts are required.');
            }
            $this->typeModel->create([
                'type_name'            => trim($_POST['type_name']),
                'asset_gl_account_id'  => $_POST['asset_gl_account_id'],
                'income_gl_account_id' => $_POST['income_gl_account_id'],
                'loss_gl_account_id'   => $_POST['loss_gl_account_id'] ?: null,
                'description'          => trim($_POST['description'] ?? ''),
                'is_active'            => 1,
            ]);
            Session::flash('success', 'Investment type created.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=investment-types');
        exit;
    }

    public function typeToggle(): void
    {
        if (!Session::hasRole(['admin'])) {
            http_response_code(403);
            die('Access denied. Admin privileges required to manage investment types.');
        }
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=investment-types');
            exit;
        }

        $id = (int)($_POST['type_id'] ?? 0);
        try {
            $type = $this->typeModel->find($id);
            if (!$type) {
                throw new InvalidArgumentException('Investment type not found.');
            }
            $this->typeModel->update($id, ['is_active' => $type['is_active'] ? 0 : 1]);
            Session::flash('success', 'Investment type updated.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=investment-types');
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
