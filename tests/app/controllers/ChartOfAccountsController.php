<?php
/**
 * ChartOfAccountsController — browse, search/filter, and (admin-only)
 * create/activate/deactivate Chart of Accounts entries.
 */
class ChartOfAccountsController extends Controller
{
    private AccountModel $model;

    public function __construct()
    {
        Session::requireAuth();

        // System Administrator role refinement (SA-1, 2026-09): view access
        // widened to include system_admin -- Chart of Accounts (which GL
        // accounts exist) is configuration, not transaction posting.
        // requireAdmin() below (the write gate) is widened the same way.
        if (!Session::hasRole(['admin', 'treasurer', 'viewer', 'chairman', 'system_admin'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view the Chart of Accounts.');
        }

        $this->model = new AccountModel();
    }

    /** Chairman role-refinement (2026-09): Chairman is governance/approval,
     *  not accounting administration -- narrowed from admin/chairman to
     *  admin/treasurer, matching "Treasurer = Financial Operations &
     *  Accounting" in the six-role model. Chairman keeps view-only access
     *  via the constructor gate above, unchanged. */
    private function requireAdmin(): void
    {
        if (!Session::hasRole(['admin', 'treasurer', 'system_admin'])) {
            http_response_code(403);
            die('Access denied. Admin, Treasurer, or System Administrator privileges required.');
        }
    }

    public function index(): void
    {
        $search = trim($_GET['search'] ?? '');
        $type   = trim($_GET['type'] ?? '');
        $status = trim($_GET['status'] ?? '');

        $accounts = $this->model->allAccounts();

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $accounts = array_values(array_filter($accounts, function ($a) use ($needle) {
                return str_contains(mb_strtolower($a['code']), $needle) || str_contains(mb_strtolower($a['name']), $needle);
            }));
        }
        if ($type !== '') {
            $accounts = array_values(array_filter($accounts, fn($a) => $a['type'] === $type));
        }
        if ($status !== '') {
            $wantActive = $status === 'active' ? 1 : 0;
            $accounts = array_values(array_filter($accounts, fn($a) => (int)$a['is_active'] === $wantActive));
        }

        $grouped = ['asset' => [], 'liability' => [], 'equity' => [], 'income' => [], 'expense' => []];
        foreach ($accounts as $a) {
            $grouped[$a['type']][] = $a;
        }

        $this->render('accounts/index', [
            'pageTitle' => 'Chart of Accounts',
            'grouped' => $grouped,
            'search' => $search,
            'type' => $type,
            'status' => $status,
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function view(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $account = $this->model->find($id);
        if (!$account) {
            http_response_code(404);
            die('Account not found.');
        }

        $parent = !empty($account['parent_id']) ? $this->model->find((int)$account['parent_id']) : null;
        $children = array_values(array_filter($this->model->allAccounts(), fn($a) => (int)($a['parent_id'] ?? 0) === $id));
        $hasActivity = $this->model->hasJournalActivity($id);

        $this->render('accounts/view', [
            'pageTitle' => $account['code'] . ' — ' . $account['name'],
            'account' => $account,
            'parent' => $parent,
            'children' => $children,
            'hasActivity' => $hasActivity,
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function create(): void
    {
        $this->requireAdmin();

        $this->render('accounts/form', [
            'pageTitle' => 'Create Account',
            'csrfToken' => $this->getCsrf(),
            'errors' => Session::flash('form_errors') ?? [],
            'old' => Session::flash('form_old') ?? [],
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=account-create');
            exit;
        }

        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? '';
        $normalBalance = $_POST['normal_balance'] ?? '';
        $subtype = trim($_POST['subtype'] ?? '') ?: null;
        $description = trim($_POST['description'] ?? '') ?: null;

        $errors = [];
        if ($code === '') $errors['code'] = 'Account code is required.';
        elseif ($this->model->findByCode($code)) $errors['code'] = 'This account code already exists.';
        if ($name === '') $errors['name'] = 'Account name is required.';
        if (!in_array($type, ['asset', 'liability', 'equity', 'income', 'expense'], true)) $errors['type'] = 'Please select a valid account type.';
        if (!in_array($normalBalance, ['debit', 'credit'], true)) $errors['normal_balance'] = 'Please select a valid normal balance.';

        if ($errors) {
            Session::flash('form_errors', $errors);
            Session::flash('form_old', $_POST);
            header('Location: ' . APP_URL . '/index.php?page=account-create');
            exit;
        }

        $id = $this->model->create([
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'subtype' => $subtype,
            'normal_balance' => $normalBalance,
            'parent_id' => !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null,
            'is_system' => 0,
            'is_active' => 1,
            'description' => $description,
        ]);

        if ($id) {
            Session::flash('success', "Account {$code} — {$name} created.");
            header('Location: ' . APP_URL . '/index.php?page=account-view&id=' . $id);
        } else {
            Session::flash('error', 'Failed to create account.');
            header('Location: ' . APP_URL . '/index.php?page=account-create');
        }
        exit;
    }

    public function toggleStatus(): void
    {
        $this->requireAdmin();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=chart-of-accounts');
            exit;
        }

        $id = (int)($_POST['account_id'] ?? 0);
        $account = $this->model->find($id);
        if (!$account) {
            Session::flash('error', 'Account not found.');
            header('Location: ' . APP_URL . '/index.php?page=chart-of-accounts');
            exit;
        }

        $newStatus = $account['is_active'] ? 0 : 1;
        $this->model->update($id, ['is_active' => $newStatus]);

        Session::flash('success', "Account {$account['code']} — {$account['name']} " . ($newStatus ? 'activated' : 'deactivated') . '.');
        header('Location: ' . APP_URL . '/index.php?page=account-view&id=' . $id);
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
