<?php
/**
 * InternalVoucherController — Internal Debit/Credit Voucher maker-checker
 * workflow.
 *
 * View: admin/treasurer/viewer/chairman. Create/submit/post: admin/treasurer.
 * Approve/reject (checker): CHAIRMAN ONLY (2026-09, explicit user decision:
 * "just remove the approval for vouchers" from admin -- admin retains
 * every other capability unchanged, including viewing/creating/posting
 * vouchers and approving other workflow types (adjustments/investments/
 * opening balances/loans); this narrows voucher approval specifically).
 * The preparer may never approve their own voucher (enforced in
 * InternalVoucherModel) -- moot for admin now since admin cannot approve
 * at all, still relevant for chairman.
 */
class InternalVoucherController extends Controller
{
    private InternalVoucherModel $model;

    public function __construct()
    {
        Session::requireAuth();

        // Stage 23: Vice Chairman and Secretary both gain approval
        // authority on this workflow below, so both need view access too.
        if (!Session::hasRole(['admin', 'treasurer', 'viewer', 'chairman', 'vice_chairman', 'secretary'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view internal vouchers.');
        }

        $this->model = new InternalVoucherModel();
    }

    private function requireWriteAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer'])) {
            http_response_code(403);
            die('Access denied. Admin or Treasurer privileges required.');
        }
    }

    private function requireApproverAccess(): void
    {
        // 2026-09: narrowed from admin+chairman to chairman-only, per
        // explicit user decision -- voucher approval is Chairman's
        // responsibility alone now. Every other admin capability in this
        // controller (view/create/post) is unchanged.
        //
        // Stage 23 (2026-09): Vice Chairman added as an explicit deputy/
        // alternate for Chairman, and Secretary added as a co-approver on
        // this specific workflow -- both per management's explicit Stage
        // 23 governance decision. Admin deliberately remains excluded
        // here, preserving the original chairman-only design intent this
        // gate was built around; the widening is additive to the board
        // officer set only, not a reversal of the 2026-09 narrowing above.
        if (!Session::hasRole(['chairman', 'vice_chairman', 'secretary'])) {
            http_response_code(403);
            die('Access denied. Chairman, Vice Chairman, or Secretary privileges required to approve or reject internal vouchers.');
        }
    }

    public function index(): void
    {
        $search   = trim($_GET['search'] ?? '');
        $type     = trim($_GET['voucher_type'] ?? '');
        $status   = trim($_GET['status'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo   = trim($_GET['date_to'] ?? '');

        $vouchers = $this->model->getAll();

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $vouchers = array_values(array_filter($vouchers, function ($v) use ($needle) {
                return str_contains(mb_strtolower($v['voucher_number']), $needle)
                    || str_contains(mb_strtolower($v['narration']), $needle);
            }));
        }
        if ($type !== '') {
            $vouchers = array_values(array_filter($vouchers, fn($v) => $v['voucher_type'] === $type));
        }
        if ($status !== '') {
            $vouchers = array_values(array_filter($vouchers, fn($v) => $v['status'] === $status));
        }
        if ($dateFrom !== '') {
            $vouchers = array_values(array_filter($vouchers, fn($v) => $v['voucher_date'] >= $dateFrom));
        }
        if ($dateTo !== '') {
            $vouchers = array_values(array_filter($vouchers, fn($v) => $v['voucher_date'] <= $dateTo));
        }

        $this->render('internal-vouchers/index', [
            'pageTitle' => 'Internal Vouchers',
            'vouchers'  => $vouchers,
            'filters'   => compact('search', 'type', 'status', 'dateFrom', 'dateTo'),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function create(): void
    {
        $this->requireWriteAccess();

        $this->render('internal-vouchers/form', [
            'pageTitle'          => 'New Internal Voucher',
            'accounts'           => (new AccountModel())->activeAccounts(),
            'expenseCategories'  => (new ExpenseCategoryModel())->activeCategories(),
            'nextVoucherNumber'  => $this->model->peekNextVoucherNumber(),
            'preparedByName'     => Session::get('user_name', 'You'),
            'csrfToken'          => $this->getCsrf(),
        ]);
    }

    public function store(): void
    {
        $this->requireWriteAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=internal-voucher-create');
            exit;
        }

        try {
            $id = $this->model->createDraft([
                'voucher_type'        => $_POST['voucher_type'] ?? null,
                'voucher_date'        => $_POST['voucher_date'] ?? null,
                'primary_account_id'  => $_POST['primary_account_id'] ?: null,
                'expense_category_id' => $_POST['expense_category_id'] ?: null,
                'contra_account_id'   => $_POST['contra_account_id'] ?? null,
                'member_id'           => $_POST['member_id'] ?: null,
                'savings_account_id'  => $_POST['savings_account_id'] ?: null,
                'share_member_id'     => $_POST['share_member_id'] ?? null,
                'contra_share_member_id' => $_POST['contra_share_member_id'] ?? null,
                'narration'           => trim($_POST['narration'] ?? ''),
                'amount'              => $_POST['amount'] ?? null,
            ], (int)Session::get('user_id'));

            Session::flash('success', 'Internal voucher created as draft.');
            header('Location: ' . APP_URL . '/index.php?page=internal-voucher-view&id=' . $id);
            exit;
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
            header('Location: ' . APP_URL . '/index.php?page=internal-voucher-create');
            exit;
        }
    }

    public function view(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $voucher = $this->model->findWithDetails($id);
        if (!$voucher) {
            http_response_code(404);
            die('Internal voucher not found.');
        }

        $currentBalance = (!empty($voucher['savings_account_id']) && $voucher['status'] !== 'posted')
            ? (new MemberSavingsAccountModel())->getAccountBalance((int)$voucher['savings_account_id'])
            : null;

        $this->render('internal-vouchers/view', [
            'pageTitle'      => 'Internal Voucher ' . $voucher['voucher_number'],
            'voucher'        => $voucher,
            'currentBalance' => $currentBalance,
            'auditTrail'     => $this->model->auditTrail($id),
            'currentUserId'  => (int)Session::get('user_id'),
            // Renamed from 'isAdmin' (2026-09): this flag now means
            // exactly one thing -- chairman-only voucher approval -- and
            // the old name was itself part of the confusion that
            // triggered this change (an admin-role account happened to
            // carry the display name "System Administrator", making an
            // admin-only approval widget look like it belonged to the
            // separate technical-administration role of the same name).
            // Stage 23: matches requireApproverAccess() exactly.
            'isApprover'     => Session::hasRole(['chairman', 'vice_chairman', 'secretary']),
            'canWrite'       => Session::hasRole(['admin', 'treasurer']),
            'csrfToken'      => $this->getCsrf(),
        ]);
    }

    public function submit(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=internal-vouchers');
            exit;
        }

        $id = (int)($_POST['voucher_id'] ?? 0);
        try {
            $this->model->submit($id, (int)Session::get('user_id'));
            $voucher = $this->model->find($id);
            $preparer = (new UserModel())->find((int)$voucher['recorded_by']);
            (new NotificationModel())->notifyVoucherSubmitted(
                $id, $voucher['voucher_number'], $preparer['full_name'] ?? 'A user', (float)$voucher['amount']
            );
            Session::flash('success', 'Internal voucher submitted for approval.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=internal-voucher-view&id=' . $id);
        exit;
    }

    public function approve(): void
    {
        $this->requireApproverAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=internal-vouchers');
            exit;
        }

        $id = (int)($_POST['voucher_id'] ?? 0);
        try {
            $this->model->approve($id, (int)Session::get('user_id'));
            $voucher = $this->model->find($id);
            (new NotificationModel())->notifyVoucherApproved(
                (int)$voucher['recorded_by'], $id, $voucher['voucher_number']
            );
            Session::flash('success', 'Internal voucher approved.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=internal-voucher-view&id=' . $id);
        exit;
    }

    public function reject(): void
    {
        $this->requireApproverAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=internal-vouchers');
            exit;
        }

        $id = (int)($_POST['voucher_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        try {
            $this->model->reject($id, (int)Session::get('user_id'), $reason);
            $voucher = $this->model->find($id);
            (new NotificationModel())->notifyVoucherRejected(
                (int)$voucher['recorded_by'], $id, $voucher['voucher_number'], $reason
            );
            Session::flash('success', 'Internal voucher rejected.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=internal-voucher-view&id=' . $id);
        exit;
    }

    public function post(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            header('Location: ' . APP_URL . '/index.php?page=internal-vouchers');
            exit;
        }

        $id = (int)($_POST['voucher_id'] ?? 0);
        try {
            $result = $this->model->post($id, (int)Session::get('user_id'));
            $voucher = $this->model->find($id);
            (new NotificationModel())->notifyVoucherPosted(
                (int)$voucher['recorded_by'], $id, $voucher['voucher_number'], (string)($result['entry_number'] ?? '')
            );
            Session::flash('success', 'Internal voucher posted as journal entry ' . ($result['entry_number'] ?? '') . '.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=internal-voucher-view&id=' . $id);
        exit;
    }

    public function print(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $voucher = $this->model->findWithDetails($id);
        if (!$voucher) {
            http_response_code(404);
            die('Internal voucher not found.');
        }

        $this->render('internal-vouchers/print', [
            'voucher'        => $voucher,
            'amountInWords'  => AmountInWordsService::convert((float)$voucher['amount']),
        ], null);
    }

    /**
     * AJAX: member search for the create form (savings-subledger accounts
     * only). Matches by name/member number/phone/etc. (MemberModel::search())
     * as well as by savings account number (e.g. "SAV-001"), merged and
     * de-duplicated by member id so typing an account number finds the
     * right member directly, without requiring the exact name first.
     */
    public function memberSearch(): void
    {
        $term = trim($_GET['q'] ?? '');
        if (strlen($term) < 2) {
            $this->json(['members' => []]);
            return;
        }
        $byName = (new MemberModel())->search($term, 'active', '', '', 1, 10)['rows'];
        $byAccount = (new SavingsAccountHolderModel())->searchMembersByAccountNumber($term, 10);

        $members = [];
        foreach (array_merge($byName, $byAccount) as $m) {
            $members[(int)$m['id']] = $m;
        }

        $out = array_map(fn($m) => [
            'id' => $m['id'], 'member_number' => $m['member_number'],
            'full_name' => $m['first_name'] . ' ' . $m['last_name'], 'phone' => $m['phone'],
        ], array_slice(array_values($members), 0, 10));
        $this->json(['members' => $out]);
    }

    /** AJAX: this member's eligible savings accounts, with live balances. */
    public function memberAccounts(): void
    {
        $memberId = (int)($_GET['member_id'] ?? 0);
        if ($memberId < 1) {
            $this->json(['accounts' => []]);
            return;
        }
        $accountModel = new MemberSavingsAccountModel();
        $accounts = array_values(array_filter(
            (new SavingsAccountHolderModel())->getMemberAccounts($memberId),
            fn($a) => $a['account_type'] !== 'corporate' && $a['status'] === 'active'
        ));
        $out = array_map(fn($a) => [
            'id'             => (int)$a['id'],
            'account_number' => $a['account_number'],
            'account_type'   => ucfirst($a['account_type']),
            'balance'        => $accountModel->getAccountBalance((int)$a['id']),
        ], $accounts);
        $this->json(['accounts' => $out]);
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
