<?php
/**
 * ExpenseController — record expenses and post them through JournalService.
 * View: admin/treasurer/viewer. Create/store/post/category management: admin/treasurer.
 */
class ExpenseController extends Controller
{
    private ExpenseModel $model;
    private ExpenseCategoryModel $categoryModel;

    public function __construct()
    {
        Session::requireAuth();

        if (!Session::hasRole(['admin', 'treasurer', 'viewer', 'chairman'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view expenses.');
        }

        $this->model = new ExpenseModel();
        $this->categoryModel = new ExpenseCategoryModel();
    }

    private function requireWriteAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer'])) {
            http_response_code(403);
            die('Access denied. Admin or Treasurer privileges required.');
        }
    }

    public function index(): void
    {
        $this->render('expenses/index', [
            'pageTitle' => 'Expenses',
            'expenses' => $this->model->getAll(),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function create(): void
    {
        $this->requireWriteAccess();

        $this->render('expenses/form', [
            'pageTitle'          => 'Record Expense',
            'categories'         => $this->categoryModel->activeCategories(),
            'categoriesGrouped'  => $this->categoryModel->activeCategoriesGrouped(),
            'csrfToken'          => $this->getCsrf(),
        ]);
    }

    public function store(): void
    {
        $this->requireWriteAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=expense-create');
            exit;
        }

        try {
            $id = $this->model->createDraft([
                'category_id'      => $_POST['category_id'] ?? null,
                'expense_date'     => $_POST['expense_date'] ?? null,
                'amount'           => $_POST['amount'] ?? null,
                'description'      => trim($_POST['description'] ?? ''),
                'reference_number' => trim($_POST['reference_number'] ?? ''),
                'payment_method'   => $_POST['payment_method'] ?? null,
                'payee_name'       => trim($_POST['payee_name'] ?? ''),
            ], (int)Session::get('user_id'));

            Session::flash('success', 'Expense recorded as draft.');
            header('Location: ' . APP_URL . '/index.php?page=expense-view&id=' . $id);
            exit;
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
            header('Location: ' . APP_URL . '/index.php?page=expense-create');
            exit;
        }
    }

    public function view(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $expense = $this->model->findWithDetails($id);
        if (!$expense) {
            http_response_code(404);
            die('Expense not found.');
        }

        $this->render('expenses/view', [
            'pageTitle' => 'Expense ' . $expense['expense_number'],
            'expense' => $expense,
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function post(): void
    {
        $this->requireWriteAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=expenses');
            exit;
        }

        $id = (int)($_POST['expense_id'] ?? 0);
        try {
            $result = $this->model->post($id, (int)Session::get('user_id'));
            Session::flash('success', 'Expense posted as journal entry ' . ($result['entry_number'] ?? '') . '.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }

        header('Location: ' . APP_URL . '/index.php?page=expense-view&id=' . $id);
        exit;
    }

    public function categories(): void
    {
        $this->render('expenses/categories', [
            'pageTitle' => 'Expense Categories',
            'categories' => $this->categoryModel->allWithAccounts(),
            'accounts' => (new AccountModel())->activeAccounts(),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function categoryStore(): void
    {
        $this->requireWriteAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=expense-categories');
            exit;
        }

        try {
            $name = trim($_POST['category_name'] ?? '');
            if ($name === '') {
                throw new InvalidArgumentException('Category name is required.');
            }
            if (empty($_POST['gl_account_id'])) {
                throw new InvalidArgumentException('A GL account is required — every expense category must be mapped to an account before it can be used, so expenses recorded under it can actually post to the ledger.');
            }

            $this->categoryModel->create([
                'category_name' => $name,
                'description'   => trim($_POST['description'] ?? '') ?: null,
                'gl_account_id' => (int)$_POST['gl_account_id'],
                'is_active'     => 1,
            ]);

            Session::flash('success', 'Expense category created.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }

        header('Location: ' . APP_URL . '/index.php?page=expense-categories');
        exit;
    }

    public function categoryUpdate(): void
    {
        $this->requireWriteAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=expense-categories');
            exit;
        }

        try {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['category_name'] ?? '');

            if ($id <= 0) {
                throw new InvalidArgumentException('Invalid category ID.');
            }
            if ($name === '') {
                throw new InvalidArgumentException('Category name is required.');
            }
            if (empty($_POST['gl_account_id'])) {
                throw new InvalidArgumentException('A GL account is required — a category cannot be saved without one mapped.');
            }

            $this->categoryModel->update($id, [
                'category_name' => $name,
                'description'   => trim($_POST['description'] ?? '') ?: null,
                'gl_account_id' => (int)$_POST['gl_account_id'],
            ]);

            Session::flash('success', 'Category updated successfully.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }

        header('Location: ' . APP_URL . '/index.php?page=expense-categories');
        exit;
    }

    public function categoryToggle(): void
    {
        $this->requireWriteAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=expense-categories');
            exit;
        }

        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('Invalid category ID.');
            }

            $category = $this->categoryModel->find($id);
            if (!$category) {
                throw new InvalidArgumentException('Category not found.');
            }

            $newState = $category['is_active'] ? 0 : 1;
            $this->categoryModel->update($id, ['is_active' => $newState]);

            Session::flash('success', 'Category ' . ($newState ? 'activated' : 'deactivated') . '.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }

        header('Location: ' . APP_URL . '/index.php?page=expense-categories');
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
