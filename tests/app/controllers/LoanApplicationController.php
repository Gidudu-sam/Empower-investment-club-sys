<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/LoanApplicationModel.php';
require_once APP_PATH  . '/models/LoanModel.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/models/LoanProductModel.php';
require_once APP_PATH  . '/models/MemberSavingsAccountModel.php';
require_once APP_PATH  . '/controllers/traits/LoanRoleAccessTrait.php';

/**
 * LoanApplicationController — Loan Applications (Stage 9)
 *
 * Routes:
 *   loan-applications          → index()
 *   loan-application-add       → add()
 *   loan-application-edit      → edit()
 *   loan-application-view      → view()
 *   loan-application-submit    → submit()
 *   loan-application-approve   → approve()
 *   loan-application-reject    → reject()
 *
 * Uses the identical requireOriginateAccess()/requireApproverAccess() role
 * gates LoanController itself uses (via the shared LoanRoleAccessTrait) --
 * this is what guarantees Application Approval and Loan Approval are
 * governed by one rule, not two that could quietly drift apart. Neither
 * gate ever includes System Admin.
 */
class LoanApplicationController extends Controller
{
    use LoanRoleAccessTrait;

    private LoanApplicationModel $model;
    private MemberModel          $memberModel;

    public function __construct()
    {
        $this->model       = new LoanApplicationModel();
        $this->memberModel = new MemberModel();
    }

    protected function roleDeniedRedirectPage(): string
    {
        return 'loan-applications';
    }

    /**
     * Stage 23: deliberately overrides LoanRoleAccessTrait's shared
     * requireApproverAccess() for THIS controller only. LoanController
     * reuses that trait method for approve/reject/disburse as one bundle
     * (disburse() is a real funds-release action); a loan APPLICATION has
     * no disburse() action at all -- approving one only changes its status
     * and records approved_amount/approved_period_months
     * (LoanApplicationModel::approve()), never moves money or touches the
     * GL. That distinction is exactly why management's Stage 23 decision
     * could safely give Secretary application-approval authority without
     * also giving Secretary loan disbursement authority: overriding here
     * (rather than editing the trait) keeps LoanController's disbursement
     * bundle untouched for every other caller of the trait.
     */
    protected function requireApproverAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'chairman', 'vice_chairman', 'secretary'])) {
            Session::flash('error', 'Access denied. Only admin, chairman, vice chairman, or secretary can approve or reject a loan application.');
            $this->redirect(APP_URL . '/index.php?page=' . $this->roleDeniedRedirectPage());
            exit;
        }
    }

    public function index(): void
    {
        Session::requireAuth();
        $this->render('loan-applications/index', [
            'pageTitle'    => 'Loan Applications — ' . APP_NAME,
            'breadcrumbs'  => [['label' => 'Loan Applications']],
            'applications' => $this->model->getAll(),
            'success'      => Session::flash('success'),
            'error'        => Session::flash('error'),
        ], 'main');
    }

    public function add(): void
    {
        $this->requireOriginateAccess();

        if ($this->isPost()) {
            $this->handleSave();
            return;
        }

        $this->render('loan-applications/form', [
            'pageTitle'         => 'New Loan Application — ' . APP_NAME,
            'breadcrumbs'       => [
                ['label' => 'Loan Applications', 'url' => APP_URL . '/index.php?page=loan-applications'],
                ['label' => 'New Application'],
            ],
            'formAction'        => APP_URL . '/index.php?page=loan-application-add',
            'formMode'          => 'add',
            'application'       => Session::flash('form_old') ?? [],
            'errors'            => Session::flash('form_errors') ?? [],
            'applicationNumber' => $this->model->generateApplicationNumber(),
            'loanTypes'         => (new LoanModel())->getLoanTypes(),
            'csrfToken'         => $this->getCsrf(),
        ], 'main');
    }

    public function edit(): void
    {
        $this->requireOriginateAccess();

        $id  = (int)($_GET['id'] ?? 0);
        $app = $this->model->find($id);
        if (!$app) { $this->abort404(); }

        if (!in_array($app['status'], ['draft', 'rejected'], true)) {
            Session::flash('error', "Application {$app['application_number']} is awaiting or has completed approval and cannot be edited.");
            $this->redirect(APP_URL . '/index.php?page=loan-application-view&id=' . $id);
            return;
        }

        if ($this->isPost()) {
            $this->handleSave($id, $app);
            return;
        }

        $old = Session::flash('form_old') ?? [];
        if ($old) $app = array_merge($app, $old);

        $this->render('loan-applications/form', [
            'pageTitle'         => 'Edit Loan Application — ' . APP_NAME,
            'breadcrumbs'       => [
                ['label' => 'Loan Applications', 'url' => APP_URL . '/index.php?page=loan-applications'],
                ['label' => 'Edit'],
            ],
            'formAction'        => APP_URL . '/index.php?page=loan-application-edit&id=' . $id,
            'formMode'          => 'edit',
            'application'       => $app,
            'errors'            => Session::flash('form_errors') ?? [],
            'applicationNumber' => $app['application_number'],
            'loanTypes'         => (new LoanModel())->getLoanTypes(),
            'csrfToken'         => $this->getCsrf(),
        ], 'main');
    }

    public function view(): void
    {
        Session::requireAuth();
        $id  = (int)($_GET['id'] ?? 0);
        $app = $this->model->findWithDetails($id);
        if (!$app) { $this->abort404(); }

        $this->render('loan-applications/view', [
            'pageTitle'   => 'Application ' . $app['application_number'] . ' — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Loan Applications', 'url' => APP_URL . '/index.php?page=loan-applications'],
                ['label' => $app['application_number']],
            ],
            'application' => $app,
            'csrfToken'   => $this->getCsrf(),
            'success'     => Session::flash('success'),
            'error'       => Session::flash('error'),
        ], 'main');
    }

    public function submit(): void
    {
        $this->requireOriginateAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loan-applications');
            return;
        }

        $id = (int)($_POST['application_id'] ?? 0);
        try {
            $this->model->submit($id, (int)Session::get('user_id'));
            Session::flash('success', 'Application submitted for approval.');
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=loan-application-view&id=' . $id);
    }

    public function approve(): void
    {
        $this->requireApproverAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loan-applications');
            return;
        }

        $id                   = (int)($_POST['application_id'] ?? 0);
        $approvedAmount       = (float)($_POST['approved_amount'] ?? 0);
        $approvedPeriodMonths = (int)($_POST['approved_period_months'] ?? 0);
        try {
            $this->model->approve($id, (int)Session::get('user_id'), $approvedAmount, $approvedPeriodMonths);
            Session::flash('success', 'Application approved.');
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=loan-application-view&id=' . $id);
    }

    public function reject(): void
    {
        $this->requireApproverAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loan-applications');
            return;
        }

        $id     = (int)($_POST['application_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        try {
            $this->model->reject($id, (int)Session::get('user_id'), $reason);
            Session::flash('success', 'Application rejected.');
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=loan-application-view&id=' . $id);
    }

    // ----------------------------------------------------------------
    // SAVE HANDLER
    // ----------------------------------------------------------------
    private function handleSave(?int $id = null, array $existing = []): void
    {
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect($id
                ? APP_URL . '/index.php?page=loan-application-edit&id=' . $id
                : APP_URL . '/index.php?page=loan-application-add');
            return;
        }

        $input  = $this->collectInput();
        $errors = $this->validate($input);

        if ($errors) {
            Session::flash('form_errors', $errors);
            Session::flash('form_old',    $input);
            $this->redirect($id
                ? APP_URL . '/index.php?page=loan-application-edit&id=' . $id
                : APP_URL . '/index.php?page=loan-application-add');
            return;
        }

        $userId = (int)Session::get('user_id');

        if ($id === null) {
            $input['application_number'] = $this->model->generateApplicationNumber();
            $input['recorded_by']        = $userId;
            $input['status']             = 'draft';
            $newId = $this->model->create($input);
            if ($newId) {
                Session::flash('success', "Application {$input['application_number']} saved as a draft. Submit it for approval when ready.");
                $this->redirect(APP_URL . '/index.php?page=loan-application-view&id=' . $newId);
            } else {
                Session::flash('error', 'Failed to save application. Please try again.');
                Session::flash('form_old', $input);
                $this->redirect(APP_URL . '/index.php?page=loan-application-add');
            }
        } else {
            unset($input['application_number'], $input['recorded_by']);
            if ($this->model->update($id, $input)) {
                Session::flash('success', 'Application updated.');
            } else {
                Session::flash('error', 'No changes saved.');
            }
            $this->redirect(APP_URL . '/index.php?page=loan-application-view&id=' . $id);
        }
    }

    private function collectInput(): array
    {
        $s = fn(string $k, string $d = '') => $this->sanitize($_POST[$k] ?? $d);

        return [
            'member_id'                 => (int)($_POST['member_id'] ?? 0),
            'loan_type_id'              => (int)($_POST['loan_type_id'] ?? 1),
            'requested_amount'          => (float)($_POST['requested_amount'] ?? 0),
            'requested_period_months'   => (int)($_POST['requested_period_months'] ?? 0),
            'purpose'                   => $s('purpose') ?: null,
            'income_source'             => in_array($_POST['income_source'] ?? '', ['salary','business'], true) ? $_POST['income_source'] : null,
            'income_details'            => $s('income_details') ?: null,
            'asset_purchase_price'      => isset($_POST['asset_purchase_price']) && $_POST['asset_purchase_price'] !== '' ? (float)$_POST['asset_purchase_price'] : null,
            'member_contribution'       => isset($_POST['member_contribution']) && $_POST['member_contribution'] !== '' ? (float)$_POST['member_contribution'] : null,
            'security_type'             => $s('security_type') ?: null,
            'security_description'      => $s('security_description') ?: null,
            'weekly_savings_commitment' => isset($_POST['weekly_savings_commitment']) && $_POST['weekly_savings_commitment'] !== '' ? (float)$_POST['weekly_savings_commitment'] : null,
        ];
    }

    private function validate(array $d): array
    {
        $e = [];

        if ($d['member_id'] < 1) {
            $e['member_id'] = 'Please select a member.';
        } elseif (!$this->memberModel->find($d['member_id'])) {
            $e['member_id'] = 'Selected member does not exist.';
        }

        if ($d['requested_amount'] <= 0) {
            $e['requested_amount'] = 'Requested amount must be greater than zero.';
        }

        if ($d['requested_period_months'] <= 0) {
            $e['requested_period_months'] = 'Please enter a requested repayment period.';
        }

        // Same product-rule validation an actual loan would face -- an
        // application that could never legally become a loan should not
        // be approvable in the first place. This does not replace the
        // re-validation performed again at conversion time (product
        // config may have changed between application and conversion);
        // it exists so an obviously-invalid application is caught early.
        if ($d['member_id'] >= 1 && $d['requested_amount'] > 0 && $d['requested_period_months'] > 0) {
            $productModel = new LoanProductModel();
            $savingsModel = new MemberSavingsAccountModel();
            $tenureMonths = $savingsModel->compulsorySavingsTenureMonths((int)$d['member_id']);
            $ruleErrors = $productModel->validateAgainstProductRules(
                (int)$d['loan_type_id'],
                (float)$d['requested_amount'],
                (int)$d['requested_period_months'],
                $tenureMonths,
                [
                    'income_source'             => $d['income_source'] ?? null,
                    'asset_purchase_price'      => $d['asset_purchase_price'] ?? null,
                    'member_contribution'       => $d['member_contribution'] ?? null,
                    'security_type'             => $d['security_type'] ?? null,
                    'security_description'      => $d['security_description'] ?? null,
                    'weekly_savings_commitment' => $d['weekly_savings_commitment'] ?? null,
                ]
            );
            if ($ruleErrors) {
                $e['product_rules'] = implode(' ', $ruleErrors);
            }
        }

        return $e;
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

    private function abort404(): never
    {
        http_response_code(404);
        require VIEW_PATH . '/errors/404.php';
        exit;
    }
}
