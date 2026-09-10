<?php
/**
 * WithdrawalPolicyController — administration UI for the configurable
 * savings withdrawal policy (Stage 17 Part E-0).
 *
 * Deliberately separate from WithdrawalController (which processes actual
 * withdrawal transactions) -- this controller only reads/writes
 * `savings_withdrawal_policies` configuration rows. It creates NO
 * withdrawal, savings, or journal records of any kind.
 *
 * Permissions mirror ChartOfAccountsController (the closest existing analog
 * for financially-sensitive configuration), since this codebase's actual,
 * live access-control mechanism is role-based (Session::hasRole()) -- the
 * separate fine-grained `permissions`/`role_permissions` tables exist in the
 * schema but are not consulted by any controller anywhere in this
 * application (confirmed by inspection), so this stage does not invent
 * permission rows nothing would check.
 */
class WithdrawalPolicyController extends Controller
{
    private WithdrawalPolicyModel $model;

    public function __construct()
    {
        Session::requireAuth();
        // System Administrator role refinement (SA-1, 2026-09): view access
        // widened to include system_admin -- the withdrawal policy TABLE
        // (percentages per account type) is configuration, not the act of
        // processing a member's withdrawal. requireAdmin() below (the
        // write gate) is widened the same way.
        if (!Session::hasRole(['admin', 'treasurer', 'viewer', 'chairman', 'system_admin'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view withdrawal policies.');
        }
        $this->model = new WithdrawalPolicyModel();
    }

    /** Chairman role-refinement (2026-09): narrowed from admin/chairman to
     *  admin/treasurer -- Chairman keeps view-only access (constructor
     *  gate, unchanged), Treasurer gains create/edit/toggle as the
     *  designated "Financial Operations & Accounting" role. */
    private function requireAdmin(): void
    {
        if (!Session::hasRole(['admin', 'treasurer', 'system_admin'])) {
            http_response_code(403);
            die('Access denied. Admin, Treasurer, or System Administrator privileges required to change withdrawal policy.');
        }
    }

    public function index(): void
    {
        $this->render('settings/withdrawal-policies/index', [
            'pageTitle'      => 'Savings Withdrawal Policies — ' . APP_NAME,
            'breadcrumbs'    => [['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'], ['label' => 'Withdrawal Policies']],
            'accountTypes'   => WithdrawalPolicyModel::ACCOUNT_TYPES,
            'currentPolicies'=> $this->model->getAllCurrentPolicies(),
            'csrfToken'      => $this->getCsrf(),
            'success'        => Session::flash('success'),
            'error'          => Session::flash('error'),
        ]);
    }

    public function history(): void
    {
        $accountType = $_GET['account_type'] ?? '';
        if (!in_array($accountType, WithdrawalPolicyModel::ACCOUNT_TYPES, true)) {
            http_response_code(404);
            die('Unknown account type.');
        }

        $this->render('settings/withdrawal-policies/history', [
            'pageTitle'   => ucfirst($accountType) . ' Withdrawal Policy History — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Withdrawal Policies', 'url' => APP_URL . '/index.php?page=withdrawal-policies'],
                ['label' => ucfirst($accountType)],
            ],
            'accountType' => $accountType,
            'history'     => $this->model->getHistoryForAccountType($accountType),
            'csrfToken'   => $this->getCsrf(),
        ]);
    }

    public function create(): void
    {
        $this->requireAdmin();

        $accountType = $_GET['account_type'] ?? '';
        if (!in_array($accountType, WithdrawalPolicyModel::ACCOUNT_TYPES, true)) {
            http_response_code(404);
            die('Unknown account type.');
        }

        $this->render('settings/withdrawal-policies/form', [
            'pageTitle'   => 'New ' . ucfirst($accountType) . ' Withdrawal Policy — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Withdrawal Policies', 'url' => APP_URL . '/index.php?page=withdrawal-policies'],
                ['label' => 'New Policy'],
            ],
            'accountType'   => $accountType,
            'currentPolicy' => $this->model->getActivePolicy($accountType),
            'frequencies'   => WithdrawalPolicyModel::FREQUENCIES,
            'csrfToken'     => $this->getCsrf(),
            'errors'        => Session::flash('form_errors') ?? [],
            'old'           => Session::flash('form_old') ?? [],
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=withdrawal-policies');
            return;
        }

        $data = $this->collectInput();

        try {
            $userId = (int)Session::get('user_id');
            $id = $this->model->createPolicy($data, $userId);
            Session::flash('success', ucfirst($data['account_type']) . ' withdrawal policy created, effective ' . $data['effective_from'] . '.');
            $this->redirect(APP_URL . '/index.php?page=withdrawal-policies');
        } catch (Throwable $e) {
            Session::flash('form_errors', [$e->getMessage()]);
            Session::flash('form_old', $data);
            $this->redirect(APP_URL . '/index.php?page=withdrawal-policy-create&account_type=' . urlencode($data['account_type']));
        }
    }

    public function editDraft(): void
    {
        $this->requireAdmin();

        $id = (int)($_GET['id'] ?? 0);
        $policy = $this->model->find($id);
        if (!$policy) {
            http_response_code(404);
            die('Policy not found.');
        }

        $this->render('settings/withdrawal-policies/edit-draft', [
            'pageTitle'   => 'Edit Draft Policy — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Withdrawal Policies', 'url' => APP_URL . '/index.php?page=withdrawal-policies'],
                ['label' => 'Edit Draft'],
            ],
            'policy'      => $policy,
            'frequencies' => WithdrawalPolicyModel::FREQUENCIES,
            'csrfToken'   => $this->getCsrf(),
            'errors'      => Session::flash('form_errors') ?? [],
        ]);
    }

    public function updateDraft(): void
    {
        $this->requireAdmin();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=withdrawal-policies');
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        $data = $this->collectInput();

        try {
            $userId = (int)Session::get('user_id');
            $this->model->updateDraft($id, $data, $userId);
            Session::flash('success', 'Draft policy updated.');
            $this->redirect(APP_URL . '/index.php?page=withdrawal-policies');
        } catch (Throwable $e) {
            Session::flash('form_errors', [$e->getMessage()]);
            $this->redirect(APP_URL . '/index.php?page=withdrawal-policy-edit-draft&id=' . $id);
        }
    }

    public function toggle(): void
    {
        $this->requireAdmin();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=withdrawal-policies');
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        try {
            $this->model->toggleStatus($id, (int)Session::get('user_id'));
            Session::flash('success', 'Policy status updated.');
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=withdrawal-policies');
    }

    private function collectInput(): array
    {
        return [
            'account_type'               => $_POST['account_type'] ?? '',
            'withdrawal_enabled'         => isset($_POST['withdrawal_enabled']) ? 1 : 0,
            'maximum_withdrawal_percent' => $_POST['maximum_withdrawal_percent'] ?? null,
            'share_conversion_percent'   => $_POST['share_conversion_percent'] ?? null,
            'frequency'                  => $_POST['frequency'] ?? '',
            'minimum_balance'            => $_POST['minimum_balance'] ?? '',
            'effective_from'             => $_POST['effective_from'] ?? '',
            'effective_to'               => $_POST['effective_to'] ?? '',
        ];
    }

    private function getCsrf(): string
    {
        if (!Session::has('csrf_token')) Session::set('csrf_token', bin2hex(random_bytes(32)));
        return Session::get('csrf_token');
    }

    private function verifyCsrf(string $token): bool
    {
        $stored = Session::get('csrf_token', '');
        Session::set('csrf_token', bin2hex(random_bytes(32)));
        return hash_equals($stored, $token);
    }
}
