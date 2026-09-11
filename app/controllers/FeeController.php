<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/FeeModel.php';

/**
 * FeeController — Fees & Charges Management
 */
class FeeController extends Controller
{
    private FeeModel $model;

    public function __construct()
    {
        $this->model = new FeeModel();
    }

    /** Fee TYPE/definition management (index/save/toggle/delete below) --
     *  configuration, not routine collection. System Administrator role
     *  refinement (SA-1, 2026-09): "configure the rules" is a settings
     *  capability distinct from "collect a fee from a member"
     *  (requireCollectAccess() below, deliberately untouched). Converted
     *  from a literal user_role !== 'admin' check to the array form used
     *  everywhere else in this controller, purely so adding system_admin
     *  here reads the same way as every other gate. */
    private function requireAdmin(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'system_admin'])) {
            Session::flash('error', 'Access denied.');
            $this->redirect(APP_URL . '/index.php?page=dashboard');
            exit;
        }
    }

    /** Stage 1 security remediation: collecting a fee is an explicit cashier
     *  and treasurer capability in the intended role model. Office
     *  Administrator (role identifier 'office_admin', renamed from the
     *  original 'front_desk') also collects registration/subscription
     *  fees in the six-role model. */
    private function requireCollectAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'treasurer', 'cashier', 'office_admin'])) {
            Session::flash('error', 'Access denied. You do not have permission to collect fees.');
            $this->redirect(APP_URL . '/index.php?page=fee-charges');
            exit;
        }
    }

    /** Waiving a fee is a discretionary write-off — admin/treasurer, not cashier. */
    private function requireWaiveAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'treasurer'])) {
            Session::flash('error', 'Access denied. Only admin or treasurer can waive a fee.');
            $this->redirect(APP_URL . '/index.php?page=fee-charges');
            exit;
        }
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

    // ================================================================
    // FEE CONFIGURATION (Settings → Fees & Charges)
    // ================================================================

    public function index(): void
    {
        $this->requireAdmin();

        $fees = $this->model->getAllFees();

        $this->render('fees/index', [
            'pageTitle'   => 'Fees & Charges — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Fees & Charges'],
            ],
            'fees'      => $fees,
            'success'   => Session::flash('success'),
            'error'     => Session::flash('error'),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function save(): void
    {
        $this->requireAdmin();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=fees');
            return;
        }

        $id            = (int)($_POST['id'] ?? 0);
        $feeName       = $this->sanitize($_POST['fee_name'] ?? '');
        $feeType       = in_array($_POST['fee_type'] ?? '', ['fixed', 'percentage']) ? $_POST['fee_type'] : 'fixed';
        $amount        = (float)($_POST['amount'] ?? 0);
        $frequency     = in_array($_POST['frequency'] ?? '', ['one_time', 'annual', 'per_loan', 'monthly']) ? $_POST['frequency'] : 'one_time';
        $description   = $this->sanitize($_POST['description'] ?? '');
        $effectiveDate = $_POST['effective_date'] ?? date('Y-m-d');
        $isActive      = isset($_POST['is_active']) ? 1 : 0;

        if (empty($feeName) || $amount <= 0) {
            Session::flash('error', 'Fee name and amount are required.');
            $this->redirect(APP_URL . '/index.php?page=fees');
            return;
        }

        $userId = (int)Session::get('user_id');

        if ($id > 0) {
            $old = $this->model->find($id);
            $this->model->updateFee($id, [
                'fee_name'       => $feeName,
                'fee_type'       => $feeType,
                'amount'         => $amount,
                'frequency'      => $frequency,
                'description'    => $description,
                'effective_date' => $effectiveDate,
                'is_active'      => $isActive,
            ]);
            if ($old && (float)$old['amount'] !== $amount) {
                $this->model->recordHistory($id, (float)$old['amount'], $amount, $userId, "Updated by admin");
            }
            $this->model->log($userId, 'fee_updated', "Updated fee: {$feeName}");
            Session::flash('success', "Fee '{$feeName}' updated.");
        } else {
            $newId = $this->model->createFee([
                'fee_name'       => $feeName,
                'fee_type'       => $feeType,
                'amount'         => $amount,
                'frequency'      => $frequency,
                'description'    => $description,
                'effective_date' => $effectiveDate,
                'is_active'      => $isActive,
            ]);
            if ($newId) {
                $this->model->recordHistory($newId, null, $amount, $userId, "Fee created");
                $this->model->log($userId, 'fee_created', "Created fee: {$feeName}");
                Session::flash('success', "Fee '{$feeName}' created.");
            }
        }

        $this->redirect(APP_URL . '/index.php?page=fees');
    }

    public function toggle(): void
    {
        $this->requireAdmin();
        // Stage 13-C (H-1): was GET-triggered with no CSRF check.
        if (!$this->isPost()) {
            Session::flash('error', 'Invalid request method.');
            $this->redirect(APP_URL . '/index.php?page=fees');
            return;
        }
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=fees');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $this->model->toggleFee($id);
            $this->model->log((int)Session::get('user_id'), 'fee_toggled', "Toggled fee ID: {$id}");
            Session::flash('success', 'Fee status updated.');
        }
        $this->redirect(APP_URL . '/index.php?page=fees');
    }

    public function delete(): void
    {
        $this->requireAdmin();
        // Stage 13-C (H-1): was GET-triggered with no CSRF check.
        if (!$this->isPost()) {
            Session::flash('error', 'Invalid request method.');
            $this->redirect(APP_URL . '/index.php?page=fees');
            return;
        }
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=fees');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            if ($this->model->deleteFee($id)) {
                $this->model->log((int)Session::get('user_id'), 'fee_deleted', "Deleted fee ID: {$id}");
                Session::flash('success', 'Fee deleted.');
            } else {
                Session::flash('error', 'Cannot delete a fee that has been charged to members.');
            }
        }
        $this->redirect(APP_URL . '/index.php?page=fees');
    }

    // ================================================================
    // MEMBER CHARGES
    // ================================================================

    public function charges(): void
    {
        Session::requireAuth();

        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $feeId  = (int)($_GET['fee_id'] ?? 0);
        $page   = max(1, (int)($_GET['p'] ?? 1));

        $result = $this->model->searchCharges($search, $status, $feeId, $page, 20);
        $fees   = $this->model->getAllFees();

        $this->render('fees/charges', [
            'pageTitle'    => 'Fee Charges — ' . APP_NAME,
            'breadcrumbs'  => [['label' => 'Fees & Charges', 'url' => APP_URL . '/index.php?page=fee-charges'], ['label' => 'Charges']],
            'charges'      => $result['rows'],
            'total'        => $result['total'],
            'pages'        => $result['pages'],
            'currentPage'  => $page,
            'search'       => $search,
            'status'       => $status,
            'feeId'        => $feeId,
            'fees'         => $fees,
            'success'      => Session::flash('success'),
            'error'        => Session::flash('error'),
            'csrfToken'    => $this->getCsrf(),
        ]);
    }

    public function markPaid(): void
    {
        $this->requireCollectAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=fee-charges');
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        $paymentMethod = $_POST['payment_method'] ?? '';
        // Stage D (approved Stage C, G1): optional, staff-supplied external
        // payment reference -- trimmed here, further normalized (empty ->
        // null) inside FeeModel::markPaid(). Never trusted for anything
        // beyond storage: it plays no role in fee/member/amount/status
        // validation, account resolution, or the journal payload.
        $externalReference = trim($_POST['external_reference'] ?? '');

        if ($id <= 0) {
            Session::flash('error', 'Invalid fee charge.');
            $this->redirect(APP_URL . '/index.php?page=fee-charges');
            return;
        }

        try {
            $userId = (int)Session::get('user_id');
            $posted = $this->model->markPaid($id, $paymentMethod, $userId, $externalReference);
            $this->model->log($userId, 'fee_paid', "Marked fee charge #{$id} as paid via {$paymentMethod} — journal entry {$posted['entry_number']}");
            $successMessage = "Fee marked as paid and posted as journal entry {$posted['entry_number']}.";
            if (!empty($posted['cash_reference_number'])) {
                $successMessage .= " Cash Reference: {$posted['cash_reference_number']}.";
            }
            Session::flash('success', $successMessage);
        } catch (Throwable $e) {
            Session::flash('error', 'Could not mark fee as paid: ' . $e->getMessage());
        }

        $this->redirect(APP_URL . '/index.php?page=fee-charges');
    }

    /** Record Fee -- manually charge a fee to a member (e.g. an annual
     *  subscription). Same tier as collecting a payment: admin, treasurer,
     *  cashier, office administrator. */
    public function chargeForm(): void
    {
        $this->requireCollectAccess();

        $this->render('fees/charge-form', [
            'pageTitle'   => 'Record Fee — ' . APP_NAME,
            'breadcrumbs' => [['label' => 'Fees & Charges', 'url' => APP_URL . '/index.php?page=fee-charges'], ['label' => 'Record Fee']],
            'fees'        => $this->model->getManuallyChargeableFees(),
            'csrfToken'   => $this->getCsrf(),
        ]);
    }

    public function chargeStore(): void
    {
        $this->requireCollectAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=fee-charge-form');
            return;
        }

        $memberId = (int)($_POST['member_id'] ?? 0);
        $feeId    = (int)($_POST['fee_id'] ?? 0);

        if ($memberId < 1) {
            Session::flash('error', 'Select a member.');
            $this->redirect(APP_URL . '/index.php?page=fee-charge-form');
            return;
        }
        require_once APP_PATH . '/models/MemberModel.php';
        if (!(new MemberModel())->find($memberId)) {
            Session::flash('error', 'Selected member does not exist.');
            $this->redirect(APP_URL . '/index.php?page=fee-charge-form');
            return;
        }

        // Optional: if a payment method is chosen right here, charge and
        // mark paid in one action (the common "member is paying now" case).
        // Left blank, this is unchanged from before -- a charge-only,
        // pay-later action, still finished via the existing "Mark Paid"
        // button on the Charge Ledger.
        $paymentMethod = trim($_POST['payment_method'] ?? '');
        $externalReference = trim($_POST['external_reference'] ?? '');

        try {
            $userId = (int)Session::get('user_id');

            if ($paymentMethod !== '') {
                $posted = $this->model->chargeAndMarkPaid($memberId, $feeId, $userId, $paymentMethod, $externalReference);
                $this->model->log($userId, 'fee_charged', "Manually charged fee ID {$feeId} to member ID {$memberId}");
                $this->model->log($userId, 'fee_paid', "Marked fee charge #{$posted['member_fee_id']} as paid via {$paymentMethod} — journal entry {$posted['entry_number']}");
                $successMessage = "Fee charged and marked as paid, posted as journal entry {$posted['entry_number']}.";
                if (!empty($posted['cash_reference_number'])) {
                    $successMessage .= " Cash Reference: {$posted['cash_reference_number']}.";
                }
                Session::flash('success', $successMessage);
            } else {
                $this->model->manualCharge($memberId, $feeId, $userId);
                $this->model->log($userId, 'fee_charged', "Manually charged fee ID {$feeId} to member ID {$memberId}");
                Session::flash('success', 'Fee charged to member. It now appears in the Charge Ledger as pending.');
            }
            $this->redirect(APP_URL . '/index.php?page=fee-charges');
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=fee-charge-form');
        }
    }

    /** AJAX: member search for the Record Fee form -- mirrors the identical
     *  pattern already used by InternalVoucherController/MemberAccountAdjustmentController. */
    public function memberSearch(): void
    {
        // Stage 13-C (H-2): this endpoint had no authentication or
        // authorization check at all -- anyone, logged in or not, could
        // enumerate members' names/numbers/phones. Gated the same way as
        // the fee-charging workflow it exists to support (chargeForm() /
        // chargeStore() above both use requireCollectAccess()); an
        // unauthorized or unauthenticated caller gets the exact same
        // redirect-based denial every other action in this controller
        // already gives, not a special AJAX-only response.
        $this->requireCollectAccess();

        require_once APP_PATH . '/models/MemberModel.php';
        $term = trim($_GET['q'] ?? '');
        if (strlen($term) < 2) {
            $this->json(['members' => []]);
            return;
        }
        $result = (new MemberModel())->search($term, 'active', '', '', 1, 10);
        $out = array_map(fn($m) => [
            'id' => $m['id'], 'member_number' => $m['member_number'],
            'full_name' => $m['first_name'] . ' ' . $m['last_name'], 'phone' => $m['phone'],
        ], $result['rows']);
        $this->json(['members' => $out]);
    }

    public function markWaived(): void
    {
        $this->requireWaiveAccess();
        // Stage 13-C Part 11 (same H-1 pattern, found in the follow-up
        // regression scan): was GET-triggered with no CSRF check.
        if (!$this->isPost()) {
            Session::flash('error', 'Invalid request method.');
            $this->redirect(APP_URL . '/index.php?page=fee-charges');
            return;
        }
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=fee-charges');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $this->model->markWaived($id);
            $this->model->log((int)Session::get('user_id'), 'fee_waived', "Waived fee charge #{$id}");
            Session::flash('success', 'Fee waived.');
        }
        $this->redirect(APP_URL . '/index.php?page=fee-charges');
    }

    // ================================================================
    // REPORTS
    // ================================================================

    public function report(): void
    {
        Session::requireAuth();

        $summary = $this->model->getReportSummary();

        $this->render('fees/report', [
            'pageTitle'   => 'Fee Reports — ' . APP_NAME,
            'breadcrumbs' => [['label' => 'Fees & Charges', 'url' => APP_URL . '/index.php?page=fee-charges'], ['label' => 'Reports']],
            'summary'     => $summary,
        ]);
    }
}
