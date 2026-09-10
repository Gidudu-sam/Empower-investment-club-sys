<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/WithdrawalModel.php';
require_once APP_PATH  . '/models/SettingsModel.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/models/SavingsModel.php';
require_once APP_PATH  . '/models/LoanModel.php';
require_once APP_PATH  . '/models/MemberSavingsAccountModel.php';
require_once APP_PATH  . '/models/WithdrawalPolicyModel.php';

/**
 * WithdrawalController
 * Routes:
 *   withdrawals            → index()
 *   withdrawal-process     → process()
 *   withdrawal-view        → view()
 *   withdrawal-receipt     → receipt()
 *   withdrawal-member      → memberWithdrawals()
 *   withdrawal-report      → report()
 *   withdrawal-member-search → memberSearch()  [AJAX]
 *   withdrawal-delete      → delete()          [Stage 17 Part E]
 *   settings               → settings()        [replaces ComingSoon]
 *   settings-save          → settingsSave()
 *
 * Stage 17 Part E: process()/handleProcess()/delete() now require
 * admin/treasurer (previously only Session::requireAuth() -- any
 * authenticated user could process a withdrawal). Read actions remain
 * open to any authenticated user, unchanged.
 */
class WithdrawalController extends Controller
{
    private WithdrawalModel            $model;
    private SettingsModel              $settings;
    private MemberModel                $memberModel;
    private SavingsModel               $savingsModel;
    private LoanModel                  $loanModel;
    private MemberSavingsAccountModel  $accountModel;
    private WithdrawalPolicyModel      $policyModel;

    public function __construct()
    {
        $this->model        = new WithdrawalModel();
        $this->settings     = new SettingsModel();
        $this->memberModel  = new MemberModel();
        $this->savingsModel = new SavingsModel();
        $this->loanModel    = new LoanModel();
        $this->accountModel = new MemberSavingsAccountModel();
        $this->policyModel  = new WithdrawalPolicyModel();
    }

    /** Reversal — undoing an already-posted withdrawal, admin/treasurer/chairman only. */
    private function requireWriteAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'treasurer', 'chairman'])) {
            http_response_code(403);
            die('Access denied. Admin, Treasurer, or Chairman privileges required to process withdrawals.');
        }
    }

    /** Stage 1 security remediation: processing a new withdrawal is an explicit
     *  cashier capability ("process withdrawals where policy allows") — the
     *  withdrawal policy engine itself, not the role check, enforces the actual
     *  limits. */
    private function requireProcessAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'treasurer', 'cashier'])) {
            http_response_code(403);
            die('Access denied. Admin, Treasurer, or Cashier privileges required to process withdrawals.');
        }
    }

    // ── LIST ────────────────────────────────────────────────
    public function index(): void
    {
        Session::requireAuth();

        $term   = trim($_GET['search'] ?? '');
        $year   = (int)($_GET['year']   ?? 0);
        $method = trim($_GET['method']  ?? '');
        $page   = max(1, (int)($_GET['p'] ?? 1));

        $result = $this->model->search($term, $year, $method, $page, 15);

        $this->render('withdrawals/index', [
            'pageTitle'    => 'Withdrawals — ' . APP_NAME,
            'breadcrumbs'  => [['label' => 'Withdrawals']],
            'withdrawals'  => $result['rows'],
            'total'        => $result['total'],
            'pages'        => $result['pages'],
            'currentPage'  => $page,
            'totalAmt'     => $result['totalAmt'],
            'search'       => $term,
            'year'         => $year,
            'method'       => $method,
            'years'        => $this->model->getYears(),
            'grandTotal'   => $this->model->totalWithdrawals(),
            'totalRetained'=> $this->model->totalRetained(),
            'annualTotal'  => $this->model->annualWithdrawals(),
            'success'      => Session::flash('success'),
            'error'        => Session::flash('error'),
        ], 'main');
    }

    // ── PROCESS (form + save) ────────────────────────────────
    public function process(): void
    {
        $this->requireProcessAccess();

        if ($this->isPost()) {
            $this->handleProcess();
            return;
        }

        $preselected = null;
        $preId       = (int)($_GET['member_id'] ?? 0);
        if ($preId > 0) {
            $preselected = $this->memberModel->find($preId);
        }

        $compulsoryPolicy = $this->policyModel->getActivePolicy('compulsory');
        $voluntaryPolicy  = $this->policyModel->getActivePolicy('voluntary');

        $this->render('withdrawals/form', [
            'pageTitle'    => 'Process Withdrawal — ' . APP_NAME,
            'breadcrumbs'  => [
                ['label' => 'Withdrawals', 'url' => APP_URL . '/index.php?page=withdrawals'],
                ['label' => 'Process Withdrawal'],
            ],
            'formAction'       => APP_URL . '/index.php?page=withdrawal-process',
            'withdrawal'       => Session::flash('form_old') ?? [],
            'errors'           => Session::flash('form_errors') ?? [],
            'wdlNumber'        => $this->model->generateNumber(),
            'csrfToken'        => $this->getCsrf(),
            'compulsoryPolicy' => $compulsoryPolicy,
            'voluntaryPolicy'  => $voluntaryPolicy,
            'preselected'      => $preselected,
            'currentYear'      => (int)date('Y'),
        ], 'main');
    }

    private function handleProcess(): void
    {
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=withdrawal-process');
            return;
        }

        $s    = fn(string $k) => $this->sanitize($_POST[$k] ?? '');
        $type = ($_POST['withdrawal_type'] ?? '') === 'voluntary' ? 'voluntary' : 'annual_compulsory';

        $data = [
            'member_id'         => (int)($_POST['member_id'] ?? 0),
            'requested_amount'  => (float)($_POST['requested_amount'] ?? 0),
            'withdrawal_date'   => $s('withdrawal_date') ?: date('Y-m-d'),
            'payment_method'    => $s('payment_method') ?: 'Cash',
            'reference_number'  => $s('reference_number') ?: null,
            'remarks'           => $s('remarks') ?: null,
        ];

        $userId = (int)Session::get('user_id');

        // Server-side authoritative processing -- WithdrawalModel
        // independently resolves the active policy and recalculates
        // everything itself; nothing computed in JavaScript or posted from
        // the form (percentages, retained amount) is ever trusted.
        try {
            $newId = $type === 'voluntary'
                ? $this->model->processVoluntary($data, $userId)
                : $this->model->processAnnualCompulsory($data, $userId);

            $withdrawal = $this->model->find($newId);
            $this->model->log($userId, 'withdrawal_processed',
                "Processed {$withdrawal['withdrawal_number']} ({$type}) for member_id {$data['member_id']} — Shs " .
                number_format((float)$withdrawal['withdrawal_amount'], 2) . " withdrawn, Shs " .
                number_format((float)$withdrawal['retained_amount'], 2) . " retained as shares");
            Session::flash('success', "Withdrawal {$withdrawal['withdrawal_number']} processed successfully.");
            $this->redirect(APP_URL . '/index.php?page=withdrawal-view&id=' . $newId);
        } catch (Throwable $e) {
            Session::flash('error', 'Could not process withdrawal: ' . $e->getMessage());
            Session::flash('form_old', $_POST);
            $this->redirect(APP_URL . '/index.php?page=withdrawal-process');
        }
    }

    // ── DELETE / REVERSE (Stage 17 Part E) ────────────────────
    public function delete(): void
    {
        $this->requireWriteAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=withdrawals');
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        $withdrawal = $this->model->find($id);
        if (!$withdrawal) {
            Session::flash('error', 'Withdrawal not found.');
            $this->redirect(APP_URL . '/index.php?page=withdrawals');
            return;
        }

        $userId = (int)Session::get('user_id');
        try {
            $this->model->reverseWithdrawal($id, $userId);
            $this->model->log($userId, 'withdrawal_reversed',
                "Reversed withdrawal {$withdrawal['withdrawal_number']} — Shs " . number_format((float)$withdrawal['withdrawal_amount'], 2));
            Session::flash('success', "Withdrawal {$withdrawal['withdrawal_number']} reversed and savings balance restored.");
        } catch (Throwable $e) {
            Session::flash('error', 'Could not reverse withdrawal: ' . $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=withdrawals');
    }

    // ── VIEW ─────────────────────────────────────────────────
    public function view(): void
    {
        Session::requireAuth();

        $id = (int)($_GET['id'] ?? 0);
        $w  = $this->model->findWithDetails($id);
        if (!$w) $this->abort404();

        $this->render('withdrawals/view', [
            'pageTitle'   => $w['withdrawal_number'] . ' — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Withdrawals', 'url' => APP_URL . '/index.php?page=withdrawals'],
                ['label' => $w['withdrawal_number']],
            ],
            'withdrawal' => $w,
            'csrfToken'  => $this->getCsrf(),
            'success'    => Session::flash('success'),
        ], 'main');
    }

    // ── RECEIPT ──────────────────────────────────────────────
    public function receipt(): void
    {
        Session::requireAuth();

        $id = (int)($_GET['id'] ?? 0);
        $w  = $this->model->findWithDetails($id);
        if (!$w) $this->abort404();

        $this->model->log((int)Session::get('user_id'), 'receipt_printed',
            "Printed withdrawal receipt {$w['withdrawal_number']}");

        $this->render('withdrawals/receipt', ['withdrawal' => $w], null);
    }

    // ── MEMBER HISTORY ───────────────────────────────────────
    public function memberWithdrawals(): void
    {
        Session::requireAuth();

        $memberId = (int)($_GET['member_id'] ?? 0);
        $member   = $this->memberModel->find($memberId);
        if (!$member) $this->abort404();

        $this->render('withdrawals/member-withdrawals', [
            'pageTitle'    => 'Withdrawals — ' . $member['first_name'] . ' ' . $member['last_name'],
            'breadcrumbs'  => [
                ['label' => 'Members', 'url' => APP_URL . '/index.php?page=members'],
                ['label' => $member['first_name'] . ' ' . $member['last_name'],
                 'url'   => APP_URL . '/index.php?page=member-view&id=' . $memberId],
                ['label' => 'Withdrawals'],
            ],
            'member'         => $member,
            'withdrawals'    => $this->model->memberHistory($memberId),
            'totalWithdrawn' => $this->model->memberTotalWithdrawn($memberId),
            'totalRetained'  => $this->model->memberTotalRetained($memberId),
        ], 'main');
    }

    // ── REPORT ───────────────────────────────────────────────
    public function report(): void
    {
        Session::requireAuth();

        $year = (int)($_GET['year'] ?? date('Y'));
        $result = $this->model->search('', $year, '', 1, 9999);

        $this->render('withdrawals/report', [
            'pageTitle'   => 'Withdrawals Report — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Withdrawals', 'url' => APP_URL . '/index.php?page=withdrawals'],
                ['label' => 'Report'],
            ],
            'withdrawals'   => $result['rows'],
            'totalWithdrawn'=> $result['totalAmt'],
            'totalRetained' => array_sum(array_column($result['rows'], 'retained_amount')),
            'year'          => $year,
            'years'         => $this->model->getYears(),
        ], 'main');
    }

    // ── AJAX: member search ──────────────────────────────────
    public function memberSearch(): void
    {
        Session::requireAuth();

        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { $this->json(['members' => []]); return; }

        $result = $this->memberModel->search($q, 'active', '', '', 1, 10);
        $currentYear = (int)date('Y');
        $out = [];
        // Stage 17 Part E: account-aware, per-account-type balances --
        // NOT SavingsModel::memberBalance() (member-level, account-type-
        // blind). Compulsory and voluntary are resolved as two entirely
        // separate accounts/balances, never aggregated together (§6).
        $compulsoryPolicy = $this->policyModel->getActivePolicy('compulsory');
        $voluntaryPolicy  = $this->policyModel->getActivePolicy('voluntary');

        foreach ($result['rows'] as $m) {
            $memberId = (int)$m['id'];
            $accounts = $this->accountModel->getMemberAccounts($memberId);
            $compulsory = null; $voluntary = null;
            foreach ($accounts as $a) {
                if ($a['account_type'] === 'compulsory') $compulsory = $a;
                if ($a['account_type'] === 'voluntary')  $voluntary  = $a;
            }

            $compulsoryBalance = $compulsory ? $this->accountModel->getAccountBalance((int)$compulsory['id']) : 0.0;
            $compulsoryQualified = $compulsory ? $this->accountModel->checkCompulsoryQualification((int)$compulsory['id'])['qualified'] : false;
            $voluntaryBalance = $voluntary ? $this->accountModel->getAccountBalance((int)$voluntary['id']) : 0.0;

            $alreadyWithdrew = $this->model->hasWithdrawnThisYear($memberId, $currentYear, 'annual_compulsory');
            $activeLoan      = $this->loanModel->memberActiveLoan($memberId);

            $out[] = [
                'id'            => $memberId,
                'member_number' => $m['member_number'],
                'full_name'     => $m['first_name'] . ' ' . $m['last_name'],
                'phone'         => $m['phone'],

                'compulsory_account_id'      => $compulsory ? (int)$compulsory['id'] : null,
                'compulsory_balance'         => (float)$compulsoryBalance,
                'compulsory_qualified'       => $compulsoryQualified,
                'compulsory_max_withdrawal'  => ($compulsory && $compulsoryPolicy) ? $this->policyModel->calculateMaximumWithdrawal($compulsoryBalance, $compulsoryPolicy) : 0.0,
                'already_withdrew_annual'    => $alreadyWithdrew,

                'voluntary_account_id'       => $voluntary ? (int)$voluntary['id'] : null,
                'voluntary_balance'          => (float)$voluntaryBalance,
                'voluntary_max_withdrawal'   => ($voluntary && $voluntaryPolicy) ? $this->policyModel->calculateMaximumWithdrawal($voluntaryBalance, $voluntaryPolicy) : 0.0,

                'loan_outstanding' => $activeLoan ? (float)$activeLoan['outstanding'] : 0,
            ];
        }
        $this->json(['members' => $out]);
    }

    // ── SETTINGS PAGE ────────────────────────────────────────
    public function settings(): void
    {
        Session::requireAuth();
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Access denied.');
            $this->redirect(APP_URL . '/index.php?page=dashboard');
            return;
        }

        $this->render('withdrawals/settings', [
            'pageTitle'   => 'Settings — ' . APP_NAME,
            'breadcrumbs' => [['label' => 'Settings']],
            'settings'    => $this->settings->getAllSettings(),
            'success'     => Session::flash('success'),
            'csrfToken'   => $this->getCsrf(),
        ], 'main');
    }

    public function settingsSave(): void
    {
        Session::requireAuth();
        if (Session::get('user_role') !== 'admin') {
            $this->redirect(APP_URL . '/index.php?page=dashboard');
            return;
        }
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=settings');
            return;
        }

        $keys = ['withdrawal_pct', 'retained_pct', 'max_withdrawals_year', 'share_value'];
        foreach ($keys as $k) {
            if (isset($_POST[$k])) {
                $this->settings->set($k, $this->sanitize($_POST[$k]));
            }
        }
        Session::flash('success', 'Settings saved successfully.');
        $this->redirect(APP_URL . '/index.php?page=settings');
    }

    // ── CSRF ─────────────────────────────────────────────────
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

    private function abort404(): never
    {
        http_response_code(404);
        require VIEW_PATH . '/errors/404.php';
        exit;
    }
}
