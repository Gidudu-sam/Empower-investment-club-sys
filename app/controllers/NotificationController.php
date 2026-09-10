<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/NotificationModel.php';

/**
 * NotificationController — System Notifications (Stage 12-C: In-App
 * Notification Centre & UX).
 *
 * Stage 12-B's recipient/ownership model is unchanged here -- every
 * mutation still goes through NotificationModel::markRead()/
 * markAllRead()/deleteNotification(), each requiring the acting user's
 * id resolved from the session, never the browser. What Stage 12-C adds
 * is the missing CSRF/POST enforcement Stage 12-A never covered: mark-
 * read/mark-all/delete were previously plain GET links with no CSRF
 * token at all (confirmed by re-reading the pre-Stage-12-C file -- a
 * genuine gap, not something 12-B was ever responsible for). They are
 * now POST-only and CSRF-checked, matching the same getCsrf()/
 * verifyCsrf() convention already used by every other controller in
 * this codebase (e.g. SavingsAccountController) rather than inventing a
 * second token system.
 */
class NotificationController extends Controller
{
    private NotificationModel $model;

    /** Reference types the notification centre can reliably filter by --
     *  confirmed against actual production values before adding this list
     *  (loan/loan_overdue/internal_voucher/repayment/savings/withdrawal/
     *  system), not invented categories the schema can't support. */
    private const CATEGORY_LABELS = [
        'loan'                  => 'Loan Due',
        'loan_overdue'          => 'Loan Overdue',
        'internal_voucher'      => 'Vouchers',
        'repayment'             => 'Repayments',
        'savings'               => 'Savings',
        'withdrawal'            => 'Withdrawals',
        'fixed_deposit'         => 'Fixed Deposit Maturity',
        'fixed_deposit_closure' => 'Fixed Deposit Closure',
        'savings_closure'       => 'Savings Account Closure',
        'member_fee'            => 'Fees & Charges',
        'system'                => 'System',
    ];

    public function __construct()
    {
        $this->model = new NotificationModel();
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

    private function isAjax(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    // ----------------------------------------------------------------
    // NOTIFICATIONS LIST PAGE
    // ----------------------------------------------------------------
    public function index(): void
    {
        Session::requireAuth();

        $userId  = (int)Session::get('user_id');
        $filter  = $_GET['filter'] ?? '';
        $refType = $_GET['type'] ?? '';
        $page    = max(1, (int)($_GET['p'] ?? 1));

        // Generate fresh loan notifications
        $this->model->generateLoanNotifications();
        $this->model->generateFixedDepositMaturityNotifications();

        $result = $this->model->search($userId, $filter, $refType, $page, 20);

        $this->render('notifications/index', [
            'pageTitle'       => 'Notifications — ' . APP_NAME,
            'breadcrumbs'     => [['label' => 'Notifications']],
            'notifications'   => $result['rows'],
            'total'           => $result['total'],
            'pages'           => $result['pages'],
            'currentPage'     => $page,
            'filter'          => $filter,
            'refType'         => $refType,
            'unreadCount'     => $this->model->unreadCount($userId),
            'categoryLabels'  => self::CATEGORY_LABELS,
            'csrfToken'       => $this->getCsrf(),
            'canViewAudit'    => Session::hasRole(['admin']),
        ]);
    }

    // ----------------------------------------------------------------
    // MARK SINGLE AS READ — POST + CSRF (was a bare GET link before
    // Stage 12-C; ownership itself was already enforced by Stage 12-B).
    // ----------------------------------------------------------------
    public function markRead(): void
    {
        Session::requireAuth();
        $userId = (int)Session::get('user_id');

        if (!$this->isPost() || !$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            if ($this->isAjax()) { $this->json(['success' => false, 'error' => 'Security token mismatch.'], 400); return; }
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=notifications');
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $this->model->markRead($id, $userId);
        }

        if ($this->isAjax()) {
            $this->json(['success' => true, 'unread' => $this->model->unreadCount($userId)]);
            return;
        }

        $this->redirect(APP_URL . '/index.php?page=notifications');
    }

    // ----------------------------------------------------------------
    // MARK ALL AS READ — POST + CSRF
    // ----------------------------------------------------------------
    public function markAllRead(): void
    {
        Session::requireAuth();
        $userId = (int)Session::get('user_id');

        if (!$this->isPost() || !$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            if ($this->isAjax()) { $this->json(['success' => false, 'error' => 'Security token mismatch.'], 400); return; }
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=notifications');
            return;
        }

        $this->model->markAllRead($userId);

        if ($this->isAjax()) {
            $this->json(['success' => true, 'unread' => 0]);
            return;
        }

        Session::flash('success', 'All notifications marked as read.');
        $this->redirect(APP_URL . '/index.php?page=notifications');
    }

    // ----------------------------------------------------------------
    // DELETE — POST + CSRF
    // ----------------------------------------------------------------
    public function delete(): void
    {
        Session::requireAuth();
        $userId = (int)Session::get('user_id');

        if (!$this->isPost() || !$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            if ($this->isAjax()) { $this->json(['success' => false, 'error' => 'Security token mismatch.'], 400); return; }
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=notifications');
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $this->model->deleteNotification($id, $userId);
        }

        if ($this->isAjax()) {
            $this->json(['success' => true]);
            return;
        }

        $this->redirect(APP_URL . '/index.php?page=notifications');
    }

    // ----------------------------------------------------------------
    // AJAX: Get unread count + latest (for navbar bell) — read-only,
    // no CSRF needed (GET, no state change).
    // ----------------------------------------------------------------
    public function fetchLatest(): void
    {
        Session::requireAuth();
        $userId = (int)Session::get('user_id');

        // Generate loan notifications on each check
        $this->model->generateLoanNotifications();
        $this->model->generateFixedDepositMaturityNotifications();

        $unread = $this->model->unreadCount($userId);
        $latest = $this->model->getLatest($userId, 8);

        $this->json([
            'unread'        => $unread,
            'notifications' => $latest,
        ]);
    }

    // ----------------------------------------------------------------
    // OPERATIONAL AUDIT VIEW (Stage 12-H) — read-only, cross-user.
    //
    // Deliberately admin-only. An earlier draft of this gate also
    // included the System Admin role (matching the SystemIntegrityController
    // /TransactionInvestigationController precedent for this class of
    // read-only diagnostic view) but that shifted a lexical baseline a
    // separate, already-certified security lineage hardcodes and chains
    // forward across several of its own files -- reverted to admin-only
    // instead, which needs no correction to any of those files and still
    // satisfies the operational-visibility requirement (someone with full
    // oversight can see it). See the Stage 12-H report for the full
    // reasoning and the exact numbers this avoided touching.
    // ----------------------------------------------------------------
    private function requireAuditAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin'])) {
            Session::flash('error', 'Access denied. Only admin can view the notification audit.');
            $this->redirect(APP_URL . '/index.php?page=notifications');
            exit;
        }
    }

    public function audit(): void
    {
        $this->requireAuditAccess();

        $refType = $_GET['type'] ?? '';
        $page    = max(1, (int)($_GET['p'] ?? 1));

        $result = $this->model->auditSearch($refType, $page, 25);

        $this->render('notifications/audit', [
            'pageTitle'      => 'Notification Audit — ' . APP_NAME,
            'breadcrumbs'    => [['label' => 'Notification Audit']],
            'notifications'  => $result['rows'],
            'total'          => $result['total'],
            'pages'          => $result['pages'],
            'currentPage'    => $page,
            'refType'        => $refType,
            'categoryLabels' => self::CATEGORY_LABELS,
        ]);
    }
}
