<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/MemberAccountAdjustmentModel.php';
require_once APP_PATH  . '/models/MemberModel.php';

/**
 * MemberAccountAdjustmentController — Member Account Credit/Debit
 * Adjustments (the audited gap: crediting/debiting a specific member's
 * account, distinct from the GL-only Internal Voucher).
 *
 * View: admin/treasurer/viewer. Create/submit/post: admin/treasurer.
 * Approve/reject/reverse: admin only, never the preparer.
 */
class MemberAccountAdjustmentController extends Controller
{
    private MemberAccountAdjustmentModel $model;
    private MemberModel $memberModel;

    public function __construct()
    {
        Session::requireAuth();
        // Stage 23: Vice Chairman added as deputy approver below, so needs
        // view access too. Secretary deliberately excluded -- management's
        // Stage 23 decision did not extend Secretary's approval authority
        // to this workflow (a member's ledger/GL correction, closer to
        // accounting than governance document review).
        if (!Session::hasRole(['admin', 'treasurer', 'viewer', 'chairman', 'vice_chairman'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view account adjustments.');
        }
        $this->model = new MemberAccountAdjustmentModel();
        $this->memberModel = new MemberModel();
    }

    private function requireWriteAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer'])) {
            http_response_code(403);
            die('Access denied. Admin or Treasurer privileges required.');
        }
    }

    /** Stage 23: Vice Chairman added as deputy/alternate approver, per
     *  management's explicit governance decision. Secretary NOT added --
     *  out of Secretary's granted scope for this stage. */
    private function requireApproverAccess(): void
    {
        if (!Session::hasRole(['admin', 'chairman', 'vice_chairman'])) {
            http_response_code(403);
            die('Access denied. Admin, Chairman, or Vice Chairman privileges required to approve, reject, or reverse an account adjustment.');
        }
    }

    public function index(): void
    {
        $this->render('member-adjustments/index', [
            'pageTitle' => 'Member Account Adjustments',
            'breadcrumbs' => [['label' => 'Accounting'], ['label' => 'Account Adjustments']],
            'adjustments' => $this->model->getAll(),
            'canWrite'    => Session::hasRole(['admin', 'treasurer']),
            'isAdmin'     => Session::hasRole(['admin', 'chairman', 'vice_chairman']),
            'success'     => Session::flash('success'),
            'error'       => Session::flash('error'),
        ]);
    }

    public function create(): void
    {
        $this->requireWriteAccess();
        $this->render('member-adjustments/form', [
            'pageTitle'          => 'New Member Account Adjustment',
            'breadcrumbs'        => [
                ['label' => 'Accounting'],
                ['label' => 'Account Adjustments', 'url' => APP_URL . '/index.php?page=member-adjustments'],
                ['label' => 'New Adjustment'],
            ],
            'nextAdjustmentNumber' => $this->model->peekNextAdjustmentNumber(),
            'accounts'           => (new AccountModel())->activeAccounts(),
            'csrfToken'          => $this->getCsrf(),
        ]);
    }

    public function store(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=member-adjustment-create');
            return;
        }

        try {
            $id = $this->model->createDraft([
                'member_id'          => $_POST['member_id'] ?? 0,
                'savings_account_id' => $_POST['savings_account_id'] ?? 0,
                'adjustment_type'    => $_POST['adjustment_type'] ?? '',
                'amount'             => $_POST['amount'] ?? 0,
                'reason'             => $_POST['reason'] ?? '',
                'original_reference' => $_POST['original_reference'] ?? '',
                'contra_account_id'  => $_POST['contra_account_id'] ?? 0,
            ], (int)Session::get('user_id'));

            Session::flash('success', 'Adjustment created as draft.');
            $this->redirect(APP_URL . '/index.php?page=member-adjustment-view&id=' . $id);
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=member-adjustment-create');
        }
    }

    public function view(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $adj = $this->model->findWithDetails($id);
        if (!$adj) {
            http_response_code(404);
            die('Adjustment not found.');
        }

        $accountModel = new MemberSavingsAccountModel();
        $this->render('member-adjustments/view', [
            'pageTitle'     => $adj['adjustment_number'] . ' — Account Adjustment',
            'breadcrumbs'   => [
                ['label' => 'Accounting'],
                ['label' => 'Account Adjustments', 'url' => APP_URL . '/index.php?page=member-adjustments'],
                ['label' => $adj['adjustment_number']],
            ],
            'adj'           => $adj,
            'currentBalance'=> $accountModel->getAccountBalance((int)$adj['savings_account_id']),
            'auditTrail'    => $this->model->auditTrail($id),
            'currentUserId' => (int)Session::get('user_id'),
            'isAdmin'       => Session::hasRole(['admin', 'chairman', 'vice_chairman']),
            'canWrite'      => Session::hasRole(['admin', 'treasurer']),
            'csrfToken'     => $this->getCsrf(),
        ]);
    }

    public function submit(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=member-adjustments');
            return;
        }
        $id = (int)($_POST['adjustment_id'] ?? 0);
        try {
            $this->model->submit($id, (int)Session::get('user_id'));
            Session::flash('success', 'Adjustment submitted for approval.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=member-adjustment-view&id=' . $id);
    }

    public function approve(): void
    {
        $this->requireApproverAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=member-adjustments');
            return;
        }
        $id = (int)($_POST['adjustment_id'] ?? 0);
        try {
            $this->model->approve($id, (int)Session::get('user_id'));
            Session::flash('success', 'Adjustment approved.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=member-adjustment-view&id=' . $id);
    }

    public function reject(): void
    {
        $this->requireApproverAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=member-adjustments');
            return;
        }
        $id = (int)($_POST['adjustment_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        try {
            $this->model->reject($id, (int)Session::get('user_id'), $reason);
            Session::flash('success', 'Adjustment rejected.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=member-adjustment-view&id=' . $id);
    }

    public function post(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=member-adjustments');
            return;
        }
        $id = (int)($_POST['adjustment_id'] ?? 0);
        try {
            $result = $this->model->post($id, (int)Session::get('user_id'));
            Session::flash('success', "Adjustment posted as journal entry {$result['entry_number']}. New balance: Shs " . number_format($result['balance_after'], 2) . '.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=member-adjustment-view&id=' . $id);
    }

    public function reverse(): void
    {
        $this->requireApproverAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=member-adjustments');
            return;
        }
        $id = (int)($_POST['adjustment_id'] ?? 0);
        $reason = trim($_POST['reversal_reason'] ?? '');
        try {
            $result = $this->model->reverse($id, (int)Session::get('user_id'), $reason);
            Session::flash('success', 'Adjustment reversed. New balance: Shs ' . number_format($result['balance_after'], 2) . '.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=member-adjustment-view&id=' . $id);
    }

    /** AJAX: member search for the create form. */
    public function memberSearch(): void
    {
        $term = trim($_GET['q'] ?? '');
        if (strlen($term) < 2) {
            $this->json(['members' => []]);
            return;
        }
        $result = $this->memberModel->search($term, 'active', '', '', 1, 10);
        $out = array_map(fn($m) => [
            'id' => $m['id'], 'member_number' => $m['member_number'],
            'full_name' => $m['first_name'] . ' ' . $m['last_name'], 'phone' => $m['phone'],
        ], $result['rows']);
        $this->json(['members' => $out]);
    }

    /** AJAX: this member's eligible accounts, with live balances — Phase 5. */
    public function memberAccounts(): void
    {
        $memberId = (int)($_GET['member_id'] ?? 0);
        if ($memberId < 1) {
            $this->json(['accounts' => []]);
            return;
        }
        $accountModel = new MemberSavingsAccountModel();
        $accounts = $this->model->eligibleAccountsForMember($memberId);
        $out = array_map(fn($a) => [
            'id'            => (int)$a['id'],
            'account_number'=> $a['account_number'],
            'account_type'  => ucfirst($a['account_type']),
            'balance'       => $accountModel->getAccountBalance((int)$a['id']),
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
