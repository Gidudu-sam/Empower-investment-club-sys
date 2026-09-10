<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/LoanModel.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/controllers/traits/LoanRoleAccessTrait.php';

/**
 * LoanController
 *
 * Routes:
 *   loans               → index()
 *   loan-add            → add()
 *   loan-edit           → edit()
 *   loan-view           → view()
 *   loan-delete         → delete()
 *   loan-complete       → markComplete()
 *   loan-member         → memberLoans()
 *   loan-member-search  → memberSearch()  [AJAX]
 */
class LoanController extends Controller
{
    private LoanModel   $model;
    private MemberModel $memberModel;

    /** Set by collectInput() when the product/rate engine cannot determine
     *  a rate for the submitted amount (e.g. an unconfigured bracket gap);
     *  checked by validate() so this surfaces as a normal form error
     *  instead of a fatal, unless the operator supplied a manual rate. */
    private ?string $rateCalcError = null;

    public function __construct()
    {
        $this->model       = new LoanModel();
        $this->memberModel = new MemberModel();
        // Auto-update overdue status on every request
        $this->model->syncOverdueStatus();
    }

    /** Stage 1 security remediation: loan origination/edit/completion/re-posting —
     *  financially significant, cashier explicitly excluded per the intended role model.
     *  Six-role model: Loans Officer added here too. Note this is a single
     *  undifferentiated gate covering origination, edit, completion, and
     *  disbursement alike — there is no separate "assess vs approve" tier
     *  in this controller to hook a narrower grant into, so Loans Officer
     *  gets the same operational access Treasurer already has here, not a
     *  restricted "prepare only" capability. Splitting disbursement/approval
     *  into its own gate would be a deeper change than adding this role. */
    private function requireWriteAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'treasurer', 'loans_officer'])) {
            Session::flash('error', 'Access denied. Only admin, treasurer, or loans officer can perform this action.');
            $this->redirect(APP_URL . '/index.php?page=loans');
            exit;
        }
    }

    // requireOriginateAccess() and requireApproverAccess() now live in
    // LoanRoleAccessTrait (Stage 9), shared with LoanApplicationController
    // so loan approval and loan-application approval are governed by one
    // definition, not two copies that could drift apart. Behavior is
    // unchanged from the versions previously defined directly here.
    use LoanRoleAccessTrait;

    /** Loans Officer role-refinement security fix (2026-09): postDisbursementAction()
     *  ("retry accounting posting") used to share requireWriteAccess() with ordinary
     *  edit/complete actions, which meant Loans Officer could independently post a
     *  live GL journal entry for a loan's disbursement -- an accounting-consequence
     *  action, not an operational edit. Deliberately NOT implemented by narrowing
     *  requireWriteAccess() itself, since Treasurer's frozen contract depends on
     *  that shared gate for edit/complete access. This is a new, separate gate used
     *  ONLY by postDisbursementAction() -- mirrors requireApproverAccess()'s
     *  admin/chairman tier (the roles who already own disbursement authority) plus
     *  Treasurer (the "authorized financial role" who may post the accounting
     *  consequence where applicable). Loans Officer is explicitly excluded. */
    private function requireDisbursementPostingAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'treasurer', 'chairman'])) {
            Session::flash('error', 'Access denied. Only admin, treasurer, or chairman can post a loan disbursement journal entry.');
            $this->redirect(APP_URL . '/index.php?page=loans');
            exit;
        }
    }

    // ----------------------------------------------------------------
    // LIST
    // ----------------------------------------------------------------
    public function index(): void
    {
        Session::requireAuth();

        $term   = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $filter = trim($_GET['filter'] ?? '');
        $page   = max(1, (int)($_GET['p'] ?? 1));

        $result  = $this->model->search($term, $status, $filter, 0, $page, 15);
        $alerts  = $this->model->getAlerts();

        // Awaiting-Approval sidebar link (Chairman) reuses this same list
        // view pre-filtered by status -- distinguish it here so the page
        // reads as its own view rather than an identical Loan Register.
        $isAwaitingApprovalView = ($status === 'pending_approval');

        $this->render('loans/index', [
            'pageTitle'   => $isAwaitingApprovalView
                ? 'Awaiting Approval — Loans — ' . APP_NAME
                : 'Loans — ' . APP_NAME,
            'breadcrumbs' => $isAwaitingApprovalView
                ? [['label' => 'Loans', 'url' => APP_URL . '/index.php?page=loans'], ['label' => 'Awaiting Approval']]
                : [['label' => 'Loans']],
            'loans'       => $result['rows'],
            'total'       => $result['total'],
            'pages'       => $result['pages'],
            'currentPage' => $page,
            'search'      => $term,
            'status'      => $status,
            'filter'      => $filter,
            'alerts'      => $alerts,
            'activeCount' => $this->model->countActive(),
            'overdueCount'=> $this->model->countOverdue(),
            'outstanding' => $this->model->totalOutstanding(),
            'success'     => Session::flash('success'),
            'error'       => Session::flash('error'),
            'csrfToken'   => $this->getCsrf(),
        ], 'main');
    }

    /** Dedicated "Awaiting Approval" queue (Chairman sidebar link). Was
     *  previously the same index()/loans-view reused with a status filter --
     *  split into its own action+view so the page's cards/table can reflect
     *  the approval funnel (pending/approved/rejected, aging) instead of the
     *  register-wide stats and post-disbursement columns that don't apply
     *  before a loan is approved. Read-only: same broad view access as
     *  index() (Session::requireAuth() only) -- the approve/reject actions
     *  it links to (via loan-view) keep their own requireApproverAccess() gate.
     */
    public function pendingApproval(): void
    {
        Session::requireAuth();

        $term = trim($_GET['search'] ?? '');
        $page = max(1, (int)($_GET['p'] ?? 1));

        $result = $this->model->pendingApprovalQueue($term, $page, 15);

        $this->render('loans/pending-approval', [
            'pageTitle'     => 'Awaiting Approval — Loans — ' . APP_NAME,
            'breadcrumbs'   => [
                ['label' => 'Loans', 'url' => APP_URL . '/index.php?page=loans'],
                ['label' => 'Awaiting Approval'],
            ],
            'loans'         => $result['rows'],
            'total'         => $result['total'],
            'pages'         => $result['pages'],
            'currentPage'   => $page,
            'search'        => $term,
            'pendingCount'  => $this->model->countPendingApproval(),
            'approvedCount' => $this->model->countApprovedThisMonth(),
            'rejectedCount' => $this->model->countRejectedThisMonth(),
            'success'       => Session::flash('success'),
            'error'         => Session::flash('error'),
        ], 'main');
    }

    // ----------------------------------------------------------------
    // ADD
    // ----------------------------------------------------------------
    public function add(): void
    {
        $this->requireOriginateAccess();

        if ($this->isPost()) {
            $this->handleSave();
            return;
        }

        $preselected = null;
        $preId = (int)($_GET['member_id'] ?? 0);
        if ($preId > 0) {
            $m = $this->memberModel->find($preId);
            if ($m) $preselected = $this->buildMemberCardData($m);
        }

        $this->render('loans/form', [
            'pageTitle'        => 'Record Loan — ' . APP_NAME,
            'breadcrumbs'      => [
                ['label' => 'Loans', 'url' => APP_URL . '/index.php?page=loans'],
                ['label' => 'Record Loan'],
            ],
            'formAction'       => APP_URL . '/index.php?page=loan-add',
            'formMode'         => 'add',
            'loan'             => Session::flash('form_old') ?? [],
            'errors'           => Session::flash('form_errors') ?? [],
            'loanNumber'       => $this->model->generateLoanNumber(),
            'loanTypes'        => $this->model->getLoanTypes(),
            'csrfToken'        => $this->getCsrf(),
            'preselected'      => $preselected,
            'conversionInfo'   => Session::flash('application_conversion_info') ?? null,
        ], 'main');
    }

    // ----------------------------------------------------------------
    // APPLICATION → LOAN CONVERSION (Stage 9)
    //
    // GET-only entry point: re-fetches the application fresh from the
    // database (never trusts anything the browser might send about its
    // content), confirms it is actually approved and not already
    // converted, then hands the loan-add form its APPROVED terms via the
    // same session-flash mechanism the form already uses to repopulate
    // itself after a validation error -- no new rendering path, no
    // duplicated form. The link is only made permanent in handleSave()'s
    // create branch below, which re-runs this exact same check again at
    // submission time (the page could have sat open for a while).
    // ----------------------------------------------------------------
    public function convertApplication(): void
    {
        $this->requireOriginateAccess();

        $appId = (int)($_GET['application_id'] ?? 0);
        require_once APP_PATH . '/models/LoanApplicationModel.php';
        $appModel = new LoanApplicationModel();

        try {
            $app = $appModel->getConvertibleOrFail($appId);
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=loan-applications');
            return;
        }

        $months = (int)$app['approved_period_months'];
        Session::flash('form_old', [
            'application_id'            => $app['id'],
            'member_id'                 => $app['member_id'],
            'loan_type_id'              => $app['loan_type_id'],
            'loan_amount'               => $app['approved_amount'],
            'loan_period_months'        => $months,
            'loan_period'               => $months . ' Month' . ($months === 1 ? '' : 's'),
            'purpose'                   => $app['purpose'],
            'income_source'             => $app['income_source'],
            'income_details'            => $app['income_details'],
            'asset_purchase_price'      => $app['asset_purchase_price'],
            'member_contribution'       => $app['member_contribution'],
            'security_type'             => $app['security_type'],
            'security_description'      => $app['security_description'],
            'weekly_savings_commitment' => $app['weekly_savings_commitment'],
        ]);
        Session::flash('application_conversion_info', [
            'application_number'      => $app['application_number'],
            'requested_amount'        => $app['requested_amount'],
            'requested_period_months' => $app['requested_period_months'],
            'approved_amount'         => $app['approved_amount'],
            'approved_period_months'  => $app['approved_period_months'],
        ]);
        $this->redirect(APP_URL . '/index.php?page=loan-add');
    }

    // ----------------------------------------------------------------
    // EDIT
    // ----------------------------------------------------------------
    public function edit(): void
    {
        $this->requireWriteAccess();

        $id   = (int)($_GET['id'] ?? 0);
        $loan = $this->findOrAbort($id);

        // Stage 8: a loan under Chairman's review must be read-only to
        // everyone, including admin -- this is what makes "approval is not
        // an editing action" real rather than a UI convention. Deliberately
        // scoped to only the two brand-new statuses (zero existing loans
        // are in either today): 'active' and every other pre-existing
        // status keep their exact current (fully editable) behavior, so
        // none of the 12 real existing loans are affected by this rule.
        if (in_array($loan['status'], ['pending_approval', 'approved'], true)) {
            Session::flash('error', "Loan {$loan['loan_number']} is awaiting approval and cannot be edited. Reject it back to draft first if changes are needed.");
            $this->redirect(APP_URL . '/index.php?page=loan-view&id=' . $id);
            return;
        }

        if ($this->isPost()) {
            $this->handleSave($id, $loan);
            return;
        }

        $old = Session::flash('form_old') ?? [];
        if ($old) $loan = array_merge($loan, $old);

        $member = $this->memberModel->find((int)$loan['member_id']);
        $preselected = $member ? $this->buildMemberCardData($member) : null;

        $this->render('loans/form', [
            'pageTitle'   => 'Edit Loan — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Loans',  'url' => APP_URL . '/index.php?page=loans'],
                ['label' => htmlspecialchars($loan['loan_number']),
                 'url'   => APP_URL . '/index.php?page=loan-view&id=' . $id],
                ['label' => 'Edit'],
            ],
            'formAction'  => APP_URL . '/index.php?page=loan-edit&id=' . $id,
            'formMode'    => 'edit',
            'loan'        => $loan,
            'errors'      => Session::flash('form_errors') ?? [],
            'loanNumber'  => $loan['loan_number'],
            'csrfToken'   => $this->getCsrf(),
            'preselected' => $preselected,
            'loanTypes'   => $this->model->getLoanTypes(),
        ], 'main');
    }

    // ----------------------------------------------------------------
    // VIEW
    // ----------------------------------------------------------------
    public function view(): void
    {
        Session::requireAuth();

        $id   = (int)($_GET['id'] ?? 0);
        $loan = $this->model->findWithDetails($id);
        if (!$loan) $this->abort404();

        // Fetch repayments & installments for this loan. forLoan() itself
        // stays chronological (ASC) since it's shared with the formal loan
        // statement/print-statement/repayment-card documents, which read
        // top-to-bottom oldest-first like a bank statement -- reversed only
        // here, for the "Payment History" tab, which is a plain recent-
        // activity list (same array_reverse() pattern already established
        // in RepaymentController::view()'s own "Recent Transactions" panel).
        require_once APP_PATH . '/models/RepaymentModel.php';
        $repaymentModel = new RepaymentModel();
        $repayments  = array_reverse($repaymentModel->forLoan($id));
        $totalPaid   = $repaymentModel->totalPaidForLoan($id);
        $installments = $this->model->getInstallments($id);

        $this->render('loans/view', [
            'pageTitle'   => $loan['loan_number'] . ' — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Loans', 'url' => APP_URL . '/index.php?page=loans'],
                ['label' => $loan['loan_number']],
            ],
            'loan'         => $loan,
            'repayments'   => $repayments,
            'totalPaid'    => $totalPaid,
            'installments' => $installments,
            'success'      => Session::flash('success'),
            'error'        => Session::flash('error'),
            'csrfToken'    => $this->getCsrf(),
        ], 'main');
    }

    // ----------------------------------------------------------------
    // DELETE
    // ----------------------------------------------------------------
    public function delete(): void
    {
        Session::requireAuth();

        // Stage 9: deleting a loan can orphan its disbursement journal entry
        // (LoanModel::delete() now reverses it, but only an admin should be
        // able to trigger that) -- restrict the same way RepaymentController
        // already does.
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Only administrators can delete loans.');
            $this->redirect(APP_URL . '/index.php?page=loans');
            return;
        }

        // Stage 13-C (H-1): this action used to mutate on a bare GET with no
        // CSRF check -- a crafted link/redirect while an admin was logged in
        // could delete a loan (reversing its disbursement journal) with no
        // confirmation. Now POST + CSRF only.
        if (!$this->isPost()) {
            Session::flash('error', 'Invalid request method.');
            $this->redirect(APP_URL . '/index.php?page=loans');
            return;
        }
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loans');
            return;
        }

        $id     = (int)($_POST['id'] ?? 0);
        $loan   = $this->findOrAbort($id);
        $userId = (int)Session::get('user_id');

        try {
            $deleted = $this->model->delete($id, $userId);
        } catch (Throwable $e) {
            Session::flash('error', "Could not delete loan {$loan['loan_number']}: " . $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=loans');
            return;
        }

        if ($deleted) {
            $this->model->log(
                $userId, 'loan_deleted',
                "Deleted loan {$loan['loan_number']}"
            );
            Session::flash('success', "Loan {$loan['loan_number']} deleted.");
        } else {
            Session::flash('error', 'Could not delete the loan record.');
        }
        $this->redirect(APP_URL . '/index.php?page=loans');
    }

    // ----------------------------------------------------------------
    // MARK COMPLETE
    // ----------------------------------------------------------------
    public function markComplete(): void
    {
        $this->requireWriteAccess();

        // Stage 13-C Part 11 (same H-1 pattern, found in the follow-up
        // regression scan): was GET-triggered with no CSRF check.
        if (!$this->isPost()) {
            Session::flash('error', 'Invalid request method.');
            $this->redirect(APP_URL . '/index.php?page=loans');
            return;
        }
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loans');
            return;
        }

        $id   = (int)($_POST['id'] ?? 0);
        $loan = $this->findOrAbort($id);

        $this->model->update($id, ['status' => 'completed', 'outstanding' => 0]);
        $this->model->log(
            (int)Session::get('user_id'), 'loan_completed',
            "Loan {$loan['loan_number']} marked as completed"
        );
        Session::flash('success', "Loan {$loan['loan_number']} marked as completed.");
        $this->redirect(APP_URL . '/index.php?page=loan-view&id=' . $id);
    }

    // ----------------------------------------------------------------
    // MEMBER LOAN HISTORY
    // ----------------------------------------------------------------
    public function memberLoans(): void
    {
        Session::requireAuth();

        $memberId = (int)($_GET['member_id'] ?? 0);
        $member   = $this->memberModel->find($memberId);
        if (!$member) $this->abort404();

        $activeLoan = $this->model->memberActiveLoan($memberId);
        $history    = $this->model->memberLoanHistory($memberId, 50);

        $this->render('loans/member-loans', [
            'pageTitle'   => 'Loans — ' . $member['first_name'] . ' ' . $member['last_name'],
            'breadcrumbs' => [
                ['label' => 'Members', 'url' => APP_URL . '/index.php?page=members'],
                ['label' => $member['first_name'] . ' ' . $member['last_name'],
                 'url'   => APP_URL . '/index.php?page=member-view&id=' . $memberId],
                ['label' => 'Loans'],
            ],
            'member'     => $member,
            'activeLoan' => $activeLoan,
            'history'    => $history,
        ], 'main');
    }

    // ----------------------------------------------------------------
    // AJAX member search — by name, member number, account number, phone
    // Returns full member info card data including current loan status
    // ----------------------------------------------------------------
    public function memberSearch(): void
    {
        Session::requireAuth();

        $term = trim($_GET['q'] ?? '');
        if (strlen($term) < 2) { $this->json(['members' => []]); return; }

        $result = $this->memberModel->search($term, '', '', '', 1, 10);
        $out = array_map(fn($m) => $this->buildMemberCardData($m), $result['rows']);

        $this->json(['members' => $out]);
    }

    // ----------------------------------------------------------------
    // AJAX — lookup member by account_number (for loan form)
    // ----------------------------------------------------------------
    public function memberLookup(): void
    {
        Session::requireAuth();

        $accNum = trim($_GET['account_number'] ?? '');
        if ($accNum === '') { $this->json(['member' => null]); return; }

        $member = $this->memberModel->findByAccountNumber($accNum);
        if (!$member) { $this->json(['member' => null]); return; }

        $this->json(['member' => $this->buildMemberCardData($member)]);
    }

    // ----------------------------------------------------------------
    // Build member info card data array (used by both AJAX methods)
    // ----------------------------------------------------------------
    private function buildMemberCardData(array $m): array
    {
        $memberId   = (int)$m['id'];
        $activeLoan = $this->model->memberActiveLoan($memberId);

        $loanStatus   = 'None';
        $outstanding  = 0;
        $loanNumber   = '';

        if ($activeLoan) {
            $loanStatus  = ucfirst($activeLoan['status'] ?? 'active');
            $outstanding = (float)($activeLoan['outstanding'] ?? 0);
            $loanNumber  = $activeLoan['loan_number'] ?? '';
        } else {
            // Check if they have any completed loans
            $completedCount = $this->model->memberCompletedLoanCount($memberId);
            if ($completedCount > 0) $loanStatus = 'Completed';
        }

        return [
            'id'             => $memberId,
            'member_number'  => $m['member_number'],
            'account_number' => $m['account_number'] ?? '',
            'full_name'      => $m['first_name'] . ' ' . $m['last_name'],
            'phone'          => $m['phone'],
            'status'         => $m['status'] ?? 'active',
            'loan_status'    => $loanStatus,
            'outstanding'    => $outstanding,
            'loan_number'    => $loanNumber,
        ];
    }

    // ----------------------------------------------------------------
    // AJAX — get product settings for dynamic form updates
    // ----------------------------------------------------------------
    public function productSettings(): void
    {
        Session::requireAuth();

        $typeId = (int)($_GET['type_id'] ?? 0);
        if ($typeId < 1) { $this->json(['error' => 'Invalid type']); return; }

        require_once APP_PATH . '/models/LoanProductModel.php';
        $productModel = new LoanProductModel();
        // Stage 9.1: sourced from loan_product_rules (a row for every one
        // of the 7 active products), not the older loan_product_settings
        // table (only ever had rows for 3) -- see getProductInfo()'s
        // docblock for why this changed.
        $settings = $productModel->getProductInfo($typeId);
        $brackets = $productModel->getBracketsOrFlatRate($typeId);

        if ($settings) {
            $this->json(['settings' => $settings, 'brackets' => $brackets]);
        } else {
            $this->json(['settings' => null, 'brackets' => []]);
        }
    }

    // ----------------------------------------------------------------
    // AJAX — generate WhatsApp schedule text
    // ----------------------------------------------------------------
    public function whatsappSchedule(): void
    {
        Session::requireAuth();

        $loanId = (int)($_GET['id'] ?? 0);
        $loan = $this->model->findWithDetails($loanId);
        if (!$loan) { $this->json(['error' => 'Loan not found']); return; }

        $installments = $this->model->getInstallments($loanId);

        require_once APP_PATH . '/models/LoanProductModel.php';
        $productModel = new LoanProductModel();
        $text = $productModel->generateWhatsAppSchedule($loan, $installments);

        $this->json(['text' => $text, 'url' => 'https://wa.me/?text=' . urlencode($text)]);
    }

    // ----------------------------------------------------------------
    // PRINT SCHEDULE (standalone printable page)
    // ----------------------------------------------------------------
    public function printSchedule(): void
    {
        Session::requireAuth();

        $loanId = (int)($_GET['id'] ?? 0);
        $loan = $this->model->findWithDetails($loanId);
        if (!$loan) { $this->abort404(); }

        $installments = $this->model->getInstallments($loanId);

        // If no installments exist, generate them now
        if (empty($installments) && (int)($loan['loan_period_months'] ?? 0) > 0) {
            $monthlyInstallment = (float)($loan['monthly_installment'] ?? 0);
            $months = (int)$loan['loan_period_months'];
            $startDate = $loan['approval_date'] ?? $loan['issue_date'];
            $loanRate = (float)$loan['interest_rate']; // Use the ACTUAL rate stored on the loan
            $loanRepFreq = $loan['repayment_frequency'] ?? 'monthly';
            $loanIntMode = $loan['interest_mode'] ?? 'percentage';
            $loanFixedAmt = (float)($loan['fixed_interest_amount'] ?? 0);

            if ($monthlyInstallment > 0 || $loanRate > 0 || $loanFixedAmt > 0) {
                // Weekly fixed-interest loans get their own schedule
                if ($loanRepFreq === 'weekly' && $loanIntMode === 'fixed' && $loanFixedAmt > 0) {
                    $this->model->generateWeeklyInterestSchedule(
                        $loanId, (float)$loan['loan_amount'], $loanFixedAmt, $months, $startDate
                    );
                } else {
                    // Determine from the PRODUCT whether this is a Business
                    // Loan (interest_only repayment_type), independent of
                    // whatever loan.repayment_method happens to hold --
                    // that column only decides WHICH interest_only-type
                    // schedule shape to regenerate, not WHETHER one applies.
                    $loanTypeId = (int)($loan['loan_type_id'] ?? 1);
                    $repType = $loan['repayment_type'] ?? 'installment';
                    try {
                        $stmt = Database::getInstance()->getConnection()->prepare("SELECT repayment_type FROM loan_types WHERE id=?");
                        $stmt->execute([$loanTypeId]);
                        $lt = $stmt->fetch();
                        if ($lt) $repType = $lt['repayment_type'];
                    } catch (PDOException $e) {}

                    if ($repType === 'interest_only') {
                        // Stage 9.2-B: genuinely branch on the loan's own
                        // stored repayment_method -- previously this
                        // always regenerated the "Standard" shape
                        // regardless (Stage 9.2-A's audit finding).
                        require_once APP_PATH . '/models/LoanProductModel.php';
                        $productModel = new LoanProductModel();
                        if (($loan['repayment_method'] ?? 'interest_only') === 'business_boost') {
                            $weeks = max(1, $months * 4);
                            $this->model->generateWeeklyInstallmentSchedule(
                                $loanId, (float)$loan['loan_amount'], (float)$loan['interest_amount'],
                                (float)$loan['total_payable'], $months, $startDate, 0
                            );
                        } else {
                            $interestOnlyMonths = max(1, $months - 2);
                            $productModel->generateBusinessBoostSchedule(
                                $loanId, (float)$loan['loan_amount'], $loanRate,
                                $months, $interestOnlyMonths, 8, $startDate
                            );
                        }
                    } elseif ($loanRepFreq === 'weekly') {
                        // Stage 9.2: a percentage-mode weekly standard-
                        // installment loan needs the real weekly generator,
                        // not the monthly-cadence one below.
                        $totalPayable = (float)$loan['total_payable'];
                        $this->model->generateWeeklyInstallmentSchedule(
                            $loanId, (float)$loan['loan_amount'], (float)$loan['interest_amount'],
                            $totalPayable, $months, $startDate, (int)($loan['grace_period_months'] ?? 0) * 4
                        );
                    } else {
                        // Standard monthly installment schedule
                        if ($monthlyInstallment <= 0) {
                            $totalPayable = (float)$loan['total_payable'];
                            $monthlyInstallment = $months > 0 ? round($totalPayable / $months, 2) : $totalPayable;
                        }
                        $this->model->generateInstallments($loanId, $monthlyInstallment, $months, $startDate);
                    }
                }
                $installments = $this->model->getInstallments($loanId);
            }
        }

        $this->render('loans/schedule', [
            'loan'         => $loan,
            'installments' => $installments,
        ], null);
    }

    // ----------------------------------------------------------------
    // LOAN STATEMENT
    // ----------------------------------------------------------------
    public function statement(): void
    {
        Session::requireAuth();

        $id   = (int)($_GET['id'] ?? 0);
        $loan = $this->model->findWithDetails($id);
        if (!$loan) $this->abort404();

        require_once APP_PATH . '/models/RepaymentModel.php';
        $repaymentModel = new RepaymentModel();
        $repayments     = $repaymentModel->forLoan($id);
        $totalPaid      = $repaymentModel->totalPaidForLoan($id);
        $installments   = $this->model->getInstallments($id);

        $this->render('loans/statement', [
            'pageTitle'    => 'Loan Statement — ' . $loan['loan_number'] . ' — ' . APP_NAME,
            'breadcrumbs'  => [
                ['label' => 'Loans', 'url' => APP_URL . '/index.php?page=loans'],
                ['label' => $loan['loan_number'], 'url' => APP_URL . '/index.php?page=loan-view&id=' . $id],
                ['label' => 'Loan Statement'],
            ],
            'loan'         => $loan,
            'repayments'   => $repayments,
            'totalPaid'    => $totalPaid,
            'installments' => $installments,
            'dateIssued'   => date('d F Y'),
        ], 'main');
    }

    public function printStatement(): void
    {
        Session::requireAuth();

        $id   = (int)($_GET['id'] ?? 0);
        $loan = $this->model->findWithDetails($id);
        if (!$loan) $this->abort404();

        require_once APP_PATH . '/models/RepaymentModel.php';
        $repaymentModel = new RepaymentModel();
        $repayments     = $repaymentModel->forLoan($id);
        $totalPaid      = $repaymentModel->totalPaidForLoan($id);
        $installments   = $this->model->getInstallments($id);

        $this->render('loans/print-statement', [
            'loan'         => $loan,
            'repayments'   => $repayments,
            'totalPaid'    => $totalPaid,
            'installments' => $installments,
            'dateIssued'   => date('d F Y'),
        ], null);
    }

    public function repaymentCard(): void
    {
        Session::requireAuth();

        $id   = (int)($_GET['id'] ?? 0);
        $loan = $this->model->findWithDetails($id);
        if (!$loan) $this->abort404();

        $installments = $this->model->getInstallments($id);

        require_once APP_PATH . '/models/RepaymentModel.php';
        $repaymentModel = new RepaymentModel();
        $repayments = $repaymentModel->forLoan($id);

        $this->render('loans/repayment-card', [
            'pageTitle'    => 'Loan Repayment Card — ' . $loan['loan_number'] . ' — ' . APP_NAME,
            'loan'         => $loan,
            'installments' => $installments,
            'repayments'   => $repayments,
            'dateIssued'   => date('d F Y'),
        ], null);
    }

    // ----------------------------------------------------------------
    // PRINTABLE SCHEDULE PAGE
    // ----------------------------------------------------------------
    public function schedule(): void
    {
        Session::requireAuth();

        $loanId = (int)($_GET['id'] ?? 0);
        $loan = $this->model->findWithDetails($loanId);
        if (!$loan) { $this->abort404(); }

        $installments = $this->model->getInstallments($loanId);

        $this->render('loans/schedule', [
            'loan'         => $loan,
            'installments' => $installments,
        ], null); // No layout — standalone page
    }

    // ----------------------------------------------------------------
    // SAVE HANDLER
    // ----------------------------------------------------------------
    private function handleSave(?int $id = null, array $existing = []): void
    {
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirectBack($id);
            return;
        }

        $input  = $this->collectInput();
        $errors = $this->validate($input, $id);

        if ($errors) {
            Session::flash('form_errors', $errors);
            Session::flash('form_old',    $input);
            $this->redirectBack($id);
            return;
        }

        $userId = (int)Session::get('user_id');

        if ($id === null) {
            $input['loan_number']  = $this->model->generateLoanNumber();
            $input['recorded_by']  = $userId;
            $input['outstanding']  = $input['total_payable'];
            $input['amount_paid']  = 0;
            // Stage 8: every new loan starts as a draft awaiting submission
            // and approval, regardless of whatever collectInput() resolved
            // status to (the form's status dropdown is for editing existing
            // legacy loans, not creation -- it is never shown/used at
            // creation time and its value is ignored here).
            $input['status']       = 'draft';

            // Application → Loan conversion (Stage 9): re-fetch and
            // re-validate the application fresh, right before creating the
            // loan -- never trust the hidden reference field for anything
            // but which row to look up. A stale/expired/already-converted
            // application fails here with a clear message instead of
            // silently creating an orphaned or duplicate-linked loan.
            $sourceApplicationId = $input['_application_id_ref'];
            unset($input['_application_id_ref']);
            $sourceApplication = null;
            if ($sourceApplicationId !== null) {
                require_once APP_PATH . '/models/LoanApplicationModel.php';
                $appModel = new LoanApplicationModel();
                try {
                    $sourceApplication = $appModel->getConvertibleOrFail($sourceApplicationId);
                } catch (InvalidArgumentException $ex) {
                    Session::flash('form_errors', ['application' => $ex->getMessage()]);
                    Session::flash('form_old', $input);
                    $this->redirect(APP_URL . '/index.php?page=loan-add');
                    return;
                }
                $input['application_id'] = $sourceApplication['id'];
            }

            $newId = $this->model->create($input);
            if ($newId) {
                if ($sourceApplication !== null) {
                    try {
                        $appModel->markConverted($sourceApplication['id'], $newId);
                    } catch (InvalidArgumentException $ex) {
                        // Someone else converted this application in the
                        // instant between the check above and here --
                        // compensate by removing the loan we just created
                        // rather than leave two loans contending for one
                        // application.
                        $this->model->delete($newId);
                        Session::flash('error', $ex->getMessage());
                        $this->redirect(APP_URL . '/index.php?page=loan-applications');
                        return;
                    }
                }
                // Generate installment schedule based on frequency and interest mode
                $repFreq = $input['repayment_frequency'] ?? 'monthly';
                $intMode = $input['interest_mode'] ?? 'percentage';
                $fixedAmt = (float)($input['fixed_interest_amount'] ?? 0);

                if ($repFreq === 'weekly' && $intMode === 'fixed' && $fixedAmt > 0) {
                    // Weekly fixed interest schedule: weekly interest payments + final principal
                    $this->model->generateWeeklyInterestSchedule(
                        $newId,
                        $input['loan_amount'],
                        $fixedAmt, // weekly interest amount
                        $input['loan_period_months'],
                        $input['approval_date'] ?? $input['issue_date']
                    );
                } elseif ($repFreq === 'weekly' && $intMode !== 'fixed' && $input['loan_period_months'] > 0) {
                    // Stage 9.2: percentage-mode weekly loans previously fell
                    // through to the monthly-only branch below, silently
                    // ignoring their own 'weekly' frequency.
                    $months = (int)$input['loan_period_months'];
                    $this->model->generateWeeklyInstallmentSchedule(
                        $newId,
                        (float)$input['loan_amount'],
                        (float)$input['interest_amount'],
                        (float)$input['total_payable'],
                        $months,
                        $input['approval_date'] ?? $input['issue_date'],
                        (int)($input['grace_period_months'] ?? 0) * 4
                    );
                } elseif ($input['loan_period_months'] > 0 && $input['monthly_installment'] > 0) {
                    $months = (int)$input['loan_period_months'];
                    $this->model->generateInstallments(
                        $newId,
                        $input['monthly_installment'],
                        $months,
                        $input['approval_date'] ?? $input['issue_date'],
                        (int)($input['grace_period_months'] ?? 0),
                        $months > 0 ? round(((float)$input['interest_amount']) / $months, 2) : 0.0,
                        (float)$input['loan_amount']
                    );
                }

                // For Business Loans: generate proper schedule based on repayment method.
                // Only $calcFinal['repayment_type'] is used below -- if the rate engine
                // can't resolve a rate (e.g. the amount is in an unconfigured bracket
                // gap, already validated/tolerated earlier since we reached this point),
                // fall back to a direct repayment_type lookup rather than failing here.
                require_once APP_PATH . '/models/LoanProductModel.php';
                $productModel = new LoanProductModel();
                try {
                    $calcFinal = $productModel->calculateLoan((int)$input['loan_type_id'], $input['loan_amount'], $input['loan_period_months']);
                } catch (InvalidArgumentException $e) {
                    $repTypeStmt = Database::getInstance()->getConnection()->prepare("SELECT repayment_type FROM loan_types WHERE id=?");
                    $repTypeStmt->execute([(int)$input['loan_type_id']]);
                    $calcFinal = ['repayment_type' => $repTypeStmt->fetchColumn() ?: 'installment'];
                }

                // Authoritative, server-calculated rate (see collectInput()) --
                // never a client-submitted value.
                $finalRate = (float)$input['interest_rate'];

                if (($calcFinal['repayment_type'] ?? '') === 'interest_only' && !($repFreq === 'weekly' && $intMode === 'fixed' && $fixedAmt > 0)) {
                    // Delete standard installments — we'll generate the correct ones
                    Database::getInstance()->getConnection()->prepare("DELETE FROM loan_installments WHERE loan_id=?")->execute([$newId]);

                    // Stage 9.2-B: genuinely branch on what was actually
                    // selected. Stage 9.2-A's audit proved this used to
                    // always run the "Standard" generator regardless of
                    // the dropdown and hardcode repayment_method to
                    // 'business_boost' on save either way -- both options
                    // produced byte-identical schedules. 'interest_only'
                    // (the dropdown's first/default option) is "Interest
                    // Only (Standard)"; 'business_boost' now gets its own
                    // real, distinct implementation.
                    $repaymentMethod = in_array($_POST['repayment_method'] ?? '', ['interest_only', 'business_boost'], true)
                        ? $_POST['repayment_method'] : 'interest_only';

                    if ($repaymentMethod === 'business_boost') {
                        // Principal + interest every period from period 1,
                        // no deferred/interest-only phase -- reuses the
                        // same weekly generator Stage 9.2 built for
                        // standard-installment products (it already
                        // guarantees an exact-sum, rounding-correct
                        // schedule); this is a parameter choice (full-term
                        // weeks, zero grace weeks), not a new engine.
                        $months = (int)$input['loan_period_months'];
                        $weeks  = max(1, $months * 4);
                        $this->model->generateWeeklyInstallmentSchedule(
                            $newId,
                            (float)$input['loan_amount'],
                            (float)$input['interest_amount'],
                            (float)$input['total_payable'],
                            $months,
                            $input['approval_date'] ?? $input['issue_date'],
                            0 // no grace weeks -- principal recovery starts week 1
                        );
                        // Runs AFTER generateBusinessBoostSchedule() would
                        // have (it isn't called on this branch at all), so
                        // no ordering dependency on that function's own
                        // internal repayment_method write.
                        $this->model->update($newId, [
                            'monthly_installment'      => round((float)$input['total_payable'] / $weeks, 2),
                            'repayment_method'         => 'business_boost',
                            'interest_only_months'     => 0,
                            'principal_recovery_weeks' => $weeks,
                        ]);
                    } else {
                        // "Interest Only (Standard)" -- unchanged from the
                        // already-correct behavior the audit verified:
                        // interest-only initial months, then a fixed
                        // 8-week window where every payment carries
                        // principal + interest, reconciling exactly to
                        // zero outstanding.
                        $interestOnlyMonths = max(1, $input['loan_period_months'] - 2);
                        $recoveryWeeks = 8;

                        $productModel->generateBusinessBoostSchedule(
                            $newId,
                            $input['loan_amount'],
                            $finalRate, // Use the actual rate from the form
                            $input['loan_period_months'],
                            $interestOnlyMonths,
                            $recoveryWeeks,
                            $input['approval_date'] ?? $input['issue_date']
                        );

                        // Recalculate totals with correct rate. This call
                        // itself hardcodes repayment_method='business_boost'
                        // internally -- the update() below runs after it
                        // and is what actually determines the final,
                        // correctly-labeled stored value.
                        $boostCalc = $productModel->calculateBusinessBoost(
                            $input['loan_amount'], $finalRate,
                            $input['loan_period_months'], $interestOnlyMonths, $recoveryWeeks
                        );
                        $this->model->update($newId, [
                            'total_payable'   => $boostCalc['total_payable'],
                            'outstanding'     => $boostCalc['total_payable'],
                            'interest_amount' => $boostCalc['total_interest'],
                            'repayment_method' => 'interest_only',
                            'interest_only_months' => $interestOnlyMonths,
                            'principal_recovery_weeks' => $recoveryWeeks,
                        ]);
                    }
                }

                // Stage 9.2: refuse to finalize a loan whose generated
                // schedule doesn't sum to its own total_payable, rather
                // than silently leaving a mismatched schedule in place.
                // Re-fetches total_payable fresh since the Business Boost
                // branch above may have updated it after the initial
                // schedule dispatch.
                $savedLoan = $this->model->find($newId);
                $scheduledTotal = (float)(Database::getInstance()->getConnection()
                    ->query("SELECT COALESCE(SUM(amount_due),0) FROM loan_installments WHERE loan_id=" . (int)$newId)
                    ->fetchColumn());
                $expectedTotal = (float)($savedLoan['total_payable'] ?? 0);
                if ($expectedTotal > 0 && abs($scheduledTotal - $expectedTotal) > 0.01) {
                    error_log("Loan {$input['loan_number']} schedule invariant failed: scheduled={$scheduledTotal} expected={$expectedTotal} -- rolling back.");
                    if ($sourceApplication !== null) {
                        Database::getInstance()->getConnection()
                            ->prepare("UPDATE loan_applications SET converted_loan_id=NULL, converted_at=NULL WHERE id=?")
                            ->execute([$sourceApplication['id']]);
                    }
                    $this->model->delete($newId);
                    Session::flash('error', 'The generated repayment schedule did not match the loan\'s total payable amount. Nothing was saved -- please try again or contact an administrator.');
                    Session::flash('form_old', $input);
                    $this->redirect(APP_URL . '/index.php?page=loan-add');
                    return;
                }

                // Auto-charge loan processing fee
                require_once APP_PATH . '/models/FeeModel.php';
                $feeModel = new FeeModel();
                $loanFeeChargeId = $feeModel->chargeLoanProcessingFee($input['member_id'], $newId, $input['loan_amount'], $userId);
                // Stage 12-G: same pattern as the registration-fee notification --
                // notify the fee-collection tier only when a genuinely new
                // pending charge was created.
                if ($loanFeeChargeId !== null) {
                    (new NotificationModel())->notifyRoles(
                        ['admin', 'treasurer', 'cashier', 'office_admin'],
                        "Loan processing fee pending",
                        "Loan processing fee charged for loan {$input['loan_number']} — awaiting payment.",
                        'info', 'member_fee', $loanFeeChargeId,
                        ['member_id' => (int)$input['member_id'], 'loan_id' => $newId, 'action_url' => APP_URL . '/index.php?page=fee-charges'],
                        "member_fee_charged:{$loanFeeChargeId}"
                    );
                }

                $this->model->log($userId, 'loan_recorded',
                    "Recorded loan {$input['loan_number']} — Shs " . number_format($input['loan_amount'], 2));

                // Stage 8: accounting posting no longer happens at creation
                // time -- it only ever fires from disburse(), and only once
                // the loan has passed through submit()/approve(). See
                // LoanModel::postDisbursement()'s own NOT_YET_APPROVED_STATUSES
                // guard, which independently blocks this regardless of caller.
                Session::flash('success', "Loan {$input['loan_number']} saved as a draft. Submit it for approval when ready.");

                $this->redirect(APP_URL . '/index.php?page=loan-view&id=' . $newId);
            } else {
                Session::flash('error', 'Failed to save loan. Please try again.');
                Session::flash('form_old', $input);
                $this->redirect(APP_URL . '/index.php?page=loan-add');
            }
        } else {
            unset($input['loan_number'], $input['recorded_by'], $input['_application_id_ref']);

            // Grace period is snapshotted once, at creation (see
            // collectInput()) -- an edit must never re-derive it from
            // whatever the product's current config happens to be, or a
            // later product-config change would retroactively rewrite an
            // existing loan's grace terms.
            unset($input['grace_period_months']);

            // Stage 8: 'draft' and 'rejected' aren't options in the status
            // dropdown (it only ever offered active/completed/overdue, for
            // legacy manual status changes), so collectInput() would coerce
            // either into 'active' by default -- silently disbursement-ready
            // status-shifting a loan that hasn't been submitted/approved.
            // Editing a draft/rejected loan must never change its status;
            // only submit()/approve()/reject()/disburse() may do that.
            if (in_array($existing['status'], ['draft', 'rejected'], true)) {
                $input['status'] = $existing['status'];
            }

            if ($this->model->update($id, $input)) {
                $this->model->log($userId, 'loan_updated',
                    "Updated loan {$existing['loan_number']} — Shs " . number_format($input['loan_amount'], 2));
                Session::flash('success', 'Loan record updated.');
            } else {
                Session::flash('error', 'No changes saved.');
            }
            $this->redirect(APP_URL . '/index.php?page=loan-view&id=' . $id);
        }
    }

    // ----------------------------------------------------------------
    // INPUT COLLECTION
    // ----------------------------------------------------------------
    private function collectInput(): array
    {
        $s = fn(string $k, string $d = '') => $this->sanitize($_POST[$k] ?? $d);
        $f = fn(string $k) => (float)($_POST[$k] ?? 0);

        $periodMonths = [
            '1 Month'   => 1,  '2 Months'  => 2,  '3 Months'  => 3,
            '4 Months'  => 4,  '5 Months'  => 5,  '6 Months'  => 6,
            '7 Months'  => 7,  '8 Months'  => 8,  '9 Months'  => 9,
            '10 Months' => 10, '11 Months' => 11, '12 Months' => 12,
            '14 Months' => 14, '16 Months' => 16, '18 Months' => 18,
            '20 Months' => 20, '24 Months' => 24,
        ];

        $issueDate    = $s('issue_date', date('Y-m-d'));
        $approvalDate = $s('approval_date') ?: $issueDate;
        $loanPeriod   = $s('loan_period');
        $months       = $periodMonths[$loanPeriod] ?? (int)($s('loan_period_months') ?: 1);
        $loanAmount   = $f('loan_amount');
        $loanTypeId   = (int)($_POST['loan_type_id'] ?? 1);
        $loanOfficer  = $s('loan_officer');

        // Auto-calculate using the calculation engine (product-aware). This
        // is the SUGGESTED rate -- always computed server-side from the
        // product + amount, never trusted from the client (Stage 9). The
        // engine throws rather than silently guessing when the amount
        // falls in an unconfigured bracket gap.
        $this->rateCalcError = null;
        try {
            $calc = $this->model->calculateLoan($loanAmount, $months, $loanTypeId);
        } catch (InvalidArgumentException $e) {
            $this->rateCalcError = $e->getMessage();
            $calc = [
                'loan_type_id' => $loanTypeId, 'repayment_type' => 'installment',
                'loan_amount' => $loanAmount, 'interest_rate' => 0, 'monthly_interest' => 0,
                'interest_amount' => 0, 'processing_fee' => 0, 'processing_fee_pct' => 0,
                'total_payable' => $loanAmount, 'monthly_installment' => 0, 'loan_period_months' => $months,
            ];
        }
        $suggestedRate = (float)$calc['interest_rate'];

        // Fixed-interest mode (Business Boost weekly loans) is a distinct,
        // legitimate product option, not a rate override -- the operator
        // sets a fixed weekly/monthly Shs amount because there is no
        // percentage-based bracket to calculate against in this mode. This
        // branch is unrelated to, and unaffected by, the interest-rate
        // enforcement fix above.
        $interestMode  = $_POST['interest_mode'] ?? 'percentage';
        $fixedInterest = (float)($_POST['fixed_interest_amount'] ?? 0);

        // Stage 9.1 -- controlled, audited rate override. The APPROVED rate
        // is whatever the operator (already authorized to reach this form
        // action -- see LoanRoleAccessTrait) submits in the dedicated
        // `approved_interest_rate` field; it is never read from the old
        // `interest_rate` field name, which is now purely the read-only
        // suggested-rate display and is never trusted for content. When no
        // approved rate is submitted at all, the suggestion is used as-is
        // (the common case). Fixed-interest mode has no percentage rate
        // concept and is unaffected. A bracket-gap amount (no suggestion
        // available) is only rescued from outright rejection by an
        // explicitly-submitted approved rate -- see validate() below.
        $rateOverridden   = false;
        $rateOverrideBy   = null;
        $rateOverrideReason = null;
        if ($interestMode !== 'fixed') {
            $approvedRateRaw = trim((string)($_POST['approved_interest_rate'] ?? ''));
            if ($approvedRateRaw !== '') {
                $approvedRate = (float)$approvedRateRaw;
                $gapRescued = ($this->rateCalcError !== null && $approvedRate > 0);
                if ($gapRescued) {
                    $this->rateCalcError = null;
                }
                if ($approvedRate > 0 && ($gapRescued || abs($approvedRate - $suggestedRate) > 0.0001)) {
                    require_once APP_PATH . '/models/LoanProductModel.php';
                    $calc = (new LoanProductModel())->applyRate($calc, $approvedRate);
                    $rateOverridden     = true;
                    $rateOverrideBy     = (int)Session::get('user_id');
                    $rateOverrideReason = $s('rate_override_reason') ?: null;
                }
            }
        }

        if ($interestMode === 'fixed' && $fixedInterest > 0) {
            // Fixed interest mode: use the exact amount entered
            // The fixedInterest value matches the repayment frequency
            $repFreq = $_POST['repayment_frequency'] ?? 'monthly';
            if ($repFreq === 'weekly') {
                // fixedInterest = weekly amount → multiply by weeks (months × 4)
                $calc['interest_rate']       = 0;
                $calc['monthly_interest']    = round($fixedInterest * 4, 2); // monthly equivalent
                $calc['interest_amount']     = round($fixedInterest * $months * 4, 2); // total over all weeks
                $calc['total_payable']       = round($loanAmount + $calc['interest_amount'], 2);
                $calc['monthly_installment'] = $fixedInterest; // weekly payment amount
            } else {
                // fixedInterest = monthly amount
                $calc['interest_rate']       = 0;
                $calc['monthly_interest']    = $fixedInterest;
                $calc['interest_amount']     = round($fixedInterest * $months, 2);
                $calc['total_payable']       = round($loanAmount + $calc['interest_amount'], 2);
                $calc['monthly_installment'] = $fixedInterest; // For business loans = recurring interest
            }
        }

        // Stage 9.2: for a percentage-mode, weekly-frequency, standard
        // installment loan, the stored recurring-payment figure must be
        // the WEEKLY amount, not total_payable/months -- matching both
        // what generateWeeklyInstallmentSchedule() actually schedules
        // below and the codebase's own existing convention that this
        // field holds "the recurring payment for whatever frequency
        // actually applies" (the fixed-weekly branch above already stores
        // a weekly figure here). Business Loan (interest_only) already
        // has its own weekly handling via generateBusinessBoostSchedule()
        // further down and is left untouched.
        $repFreq = $_POST['repayment_frequency'] ?? 'monthly';
        if ($repFreq === 'weekly' && $interestMode !== 'fixed' && ($calc['repayment_type'] ?? '') !== 'interest_only' && $months > 0) {
            $calc['monthly_installment'] = round($calc['total_payable'] / max(1, $months * 4), 2);
        }

        // Due date = approval/issue date + loan period
        $dueDate = '';
        if ($approvalDate && $months > 0) {
            try {
                $dt = new DateTime($approvalDate);
                $dt->modify("+{$months} months");
                $dueDate = $dt->format('Y-m-d');
            } catch (\Exception $e) { $dueDate = ''; }
        }

        // Next payment date = 1 month from approval
        $nextPayment = '';
        if ($approvalDate) {
            try {
                $dt = new DateTime($approvalDate);
                $dt->modify("+1 month");
                $nextPayment = $dt->format('Y-m-d');
            } catch (\Exception $e) {}
        }

        return [
            'member_id'               => (int)($_POST['member_id'] ?? 0),
            'loan_type_id'            => $loanTypeId,
            'account_number'          => $s('account_number') ?: null,
            'application_date'        => $s('application_date') ?: null,
            'approval_date'           => $approvalDate,
            'date_approved'           => $s('date_approved') ?: ($approvalDate ?: null),
            'date_issued'             => $s('date_issued') ?: ($issueDate ?: null),
            'loan_amount'             => $loanAmount,
            'approved_amount'         => (float)($_POST['approved_amount'] ?? $loanAmount) ?: $loanAmount,
            'interest_rate'           => $calc['interest_rate'],
            // Suggested/override audit trail (Stage 9.1) -- suggested_interest_rate
            // is always the server-calculated engine output, independent of
            // whatever the final (possibly-overridden) interest_rate above is.
            'suggested_interest_rate' => $interestMode === 'fixed' ? null : $suggestedRate,
            'rate_overridden'         => $rateOverridden ? 1 : 0,
            'rate_override_by'        => $rateOverrideBy,
            'rate_override_reason'    => $rateOverrideReason,
            'interest_amount'         => $calc['interest_amount'],
            'processing_fee'          => $calc['processing_fee'],
            // Server-derived, never trusted from the client -- monthly_installment
            // is purely a function of amount/rate/period, all already
            // authoritative by this point (Stage 9.1: previously this read
            // straight from $_POST, an independent trust gap alongside the
            // interest-rate one).
            'monthly_installment'     => $calc['monthly_installment'],
            'total_payable'           => $calc['total_payable'],
            'outstanding'             => $calc['total_payable'],
            'amount_paid'             => 0,
            'issue_date'              => $issueDate,
            'due_date'                => $dueDate,
            'disbursement_date'       => $issueDate,
            'disbursement_method'     => in_array($_POST['disbursement_method'] ?? '',
                                            ['Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other'], true)
                                            ? $_POST['disbursement_method'] : 'Cash',
            'loan_period'             => $loanPeriod ?: "{$months} Months",
            'loan_period_months'      => $months,
            'next_payment_date'       => $nextPayment ?: null,
            'purpose'                 => $s('purpose') ?: null,
            'remarks'                 => $s('remarks') ?: null,
            'loan_officer'            => $s('loan_officer') ?: null,
            'guarantor_name'          => $s('guarantor_name') ?: null,
            'guarantor_contact'       => $s('guarantor_contact') ?: null,
            'emergency_contact_name'  => $s('emergency_contact_name') ?: null,
            'emergency_contact_phone' => $s('emergency_contact_phone') ?: null,
            'business_name'           => $s('business_name') ?: null,
            'business_location'       => $s('business_location') ?: null,
            'repayment_frequency'     => in_array($_POST['repayment_frequency'] ?? '', ['weekly','monthly']) ? $_POST['repayment_frequency'] : 'monthly',
            'interest_mode'           => in_array($_POST['interest_mode'] ?? '', ['percentage','fixed']) ? $_POST['interest_mode'] : 'percentage',
            'fixed_interest_amount'   => (float)($_POST['fixed_interest_amount'] ?? 0),
            'status'                  => in_array($_POST['status'] ?? '', ['pending','active','completed','overdue','defaulted'])
                                            ? $_POST['status'] : 'active',
            // Product-specific eligibility capture (Stage 9). Blank/null for
            // any product that doesn't require them -- validateAgainstProductRules()
            // decides relevance from the product's own configured flags, not
            // from which of these happen to be present.
            'income_source'           => in_array($_POST['income_source'] ?? '', ['salary','business'], true) ? $_POST['income_source'] : null,
            'income_details'          => $s('income_details') ?: null,
            'asset_purchase_price'    => isset($_POST['asset_purchase_price']) && $_POST['asset_purchase_price'] !== '' ? (float)$_POST['asset_purchase_price'] : null,
            'member_contribution'     => isset($_POST['member_contribution']) && $_POST['member_contribution'] !== '' ? (float)$_POST['member_contribution'] : null,
            // A product with a fixed security type (e.g. Start-Up's
            // 'chattel') never trusts a free-text submission for it -- the
            // fixed type always wins. Only a flexible product (security
            // type left NULL in loan_product_rules, e.g. Asset Financing's
            // "asset or alternative pledge") accepts the submitted value.
            'security_type'           => $this->productFixedSecurityType($loanTypeId) ?? ($s('security_type') ?: null),
            'security_description'    => $s('security_description') ?: null,
            'weekly_savings_commitment' => isset($_POST['weekly_savings_commitment']) && $_POST['weekly_savings_commitment'] !== '' ? (float)$_POST['weekly_savings_commitment'] : null,
            // Snapshot the product's currently-configured grace period onto
            // the loan itself at creation time, so later changes to product
            // config never retroactively alter an existing loan's schedule.
            'grace_period_months'     => $this->productGraceMonths($loanTypeId),
            // Reference only -- content is never trusted from this field.
            // handleSave()'s create branch re-fetches the application fresh
            // from the database by this id and re-validates it before ever
            // attaching it to the new loan.
            '_application_id_ref'     => (int)($_POST['application_id'] ?? 0) ?: null,
        ];
    }

    /** Reads the currently-configured grace period for a loan type, straight
     *  from loan_product_rules -- the single authoritative source consulted
     *  once, at creation time, so the value snapshotted onto the loan is
     *  immune to later product-config edits. Returns 0 (no grace) if the
     *  product has no configured rule row. */
    private function productGraceMonths(int $loanTypeId): int
    {
        require_once APP_PATH . '/models/LoanProductModel.php';
        return (new LoanProductModel())->graceMonthsFor($loanTypeId);
    }

    /** See LoanProductModel::fixedSecurityTypeFor() -- null for products
     *  with no fixed security type (either none required, or a flexible
     *  one like Asset Financing's). */
    private function productFixedSecurityType(int $loanTypeId): ?string
    {
        require_once APP_PATH . '/models/LoanProductModel.php';
        return (new LoanProductModel())->fixedSecurityTypeFor($loanTypeId);
    }

    // ----------------------------------------------------------------
    // VALIDATION
    // ----------------------------------------------------------------
    private function validate(array $d, ?int $editId = null): array
    {
        $e = [];

        if ($d['member_id'] < 1) {
            $e['member_id'] = 'Please select a member.';
        } elseif (!$this->memberModel->find($d['member_id'])) {
            $e['member_id'] = 'Selected member does not exist.';
        }

        if ($d['loan_amount'] <= 0) {
            $e['loan_amount'] = 'Loan amount must be greater than zero.';
        }

        if ($d['total_payable'] <= 0) {
            $e['total_payable'] = 'Total payable must be greater than zero.';
        }

        if (empty($d['issue_date']) || !DateTime::createFromFormat('Y-m-d', $d['issue_date'])) {
            $e['issue_date'] = 'Enter a valid issue date.';
        }

        if (empty($d['loan_period'])) {
            $e['loan_period'] = 'Please select a repayment period.';
        }

        if (empty($d['due_date']) || !DateTime::createFromFormat('Y-m-d', $d['due_date'])) {
            $e['due_date'] = 'Could not calculate due date. Please select a valid issue date and repayment period.';
        } elseif (!empty($d['issue_date']) && $d['due_date'] <= $d['issue_date']) {
            $e['due_date'] = 'Due date must be after the issue date.';
        }

        // Stage 9 / 9.1: the interest-rate engine is the sole source of the
        // SUGGESTED rate; an unconfigured-bracket-gap error is fatal unless
        // collectInput() already rescued it via an explicitly-submitted,
        // audited approved_interest_rate override (in which case
        // $this->rateCalcError was cleared there). There is no path by
        // which a gap is silently papered over -- either a real suggestion
        // exists, or an authorized, recorded override was supplied, or the
        // submission is rejected.
        if ($this->rateCalcError !== null) {
            $e['interest_rate'] = $this->rateCalcError;
        }

        // Stage 9: enforce the approved amount/duration/eligibility rules
        // per product (net-new -- previously unchecked anywhere), including
        // income source / member contribution / security / weekly-savings
        // eligibility gates added by the loan-product-rules stage.
        if ($d['member_id'] >= 1 && $d['loan_amount'] > 0 && (int)($d['loan_period_months'] ?? 0) > 0) {
            require_once APP_PATH . '/models/LoanProductModel.php';
            require_once APP_PATH . '/models/MemberSavingsAccountModel.php';
            $productModel = new LoanProductModel();
            $savingsModel = new MemberSavingsAccountModel();
            $tenureMonths = $savingsModel->compulsorySavingsTenureMonths((int)$d['member_id']);
            $ruleErrors = $productModel->validateAgainstProductRules(
                (int)$d['loan_type_id'],
                (float)$d['loan_amount'],
                (int)$d['loan_period_months'],
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

    // ----------------------------------------------------------------
    // CSRF
    // ----------------------------------------------------------------
    // ----------------------------------------------------------------
    // ACCOUNTING — legacy retry route: posts a disbursement journal for a
    // loan that is already 'active' (or overdue/completed/defaulted) but
    // never got one -- exactly the state all 12 pre-Stage-8 historical
    // loans are in. Deliberately NOT usable on an 'approved' Stage 8 loan:
    // that must go through disburse() below, which posts AND correctly
    // transitions status to 'active' in the same transaction. Using this
    // route on an 'approved' loan would post the journal but leave status
    // stuck at 'approved' forever, since this method never touches status.
    // ----------------------------------------------------------------
    public function postDisbursementAction(): void
    {
        $this->requireDisbursementPostingAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loans');
            return;
        }

        $id   = (int)($_POST['loan_id'] ?? 0);
        $loan = $this->model->find($id);
        if ($loan && $loan['status'] === 'approved') {
            Session::flash('error', "Loan {$loan['loan_number']} is approved but not yet disbursed. Use the Disburse action instead of the accounting retry.");
            $this->redirect(APP_URL . '/index.php?page=loan-view&id=' . $id);
            return;
        }

        try {
            $result = $this->model->postDisbursement($id, (int)Session::get('user_id'));
            Session::flash('success', 'Loan disbursement posted as journal entry ' . ($result['entry_number'] ?? '') . '.');
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }

        $this->redirect(APP_URL . '/index.php?page=loan-view&id=' . $id);
    }

    // ----------------------------------------------------------------
    // APPROVAL WORKFLOW (Stage 8)
    // ----------------------------------------------------------------

    public function submit(): void
    {
        $this->requireWriteAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loans');
            return;
        }

        $id = (int)($_POST['loan_id'] ?? 0);
        try {
            $loan = $this->model->find($id);
            $this->model->submit($id, (int)Session::get('user_id'));
            $this->model->log((int)Session::get('user_id'), 'loan_submitted',
                "Submitted loan {$loan['loan_number']} for approval");
            // Stage 12-E: mirrors the already-certified voucher-submitted
            // pattern -- notify the approver audience (admin/chairman,
            // LoanRoleAccessTrait::requireApproverAccess()), not the same
            // operational role list used for ongoing loan management.
            (new NotificationModel())->notifyRoles(
                ['admin', 'chairman'],
                "Loan {$loan['loan_number']} awaiting approval",
                "Loan {$loan['loan_number']} (Shs " . number_format((float)$loan['loan_amount'], 2) . ") was submitted for approval.",
                'info', 'loan', $id,
                ['loan_id' => $id, 'member_id' => $loan['member_id'] ?? null, 'action_url' => APP_URL . '/index.php?page=loan-view&id=' . $id],
                "loan_submitted:{$id}"
            );
            Session::flash('success', "Loan {$loan['loan_number']} submitted for approval.");
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=loan-view&id=' . $id);
    }

    public function approve(): void
    {
        $this->requireApproverAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loans');
            return;
        }

        $id = (int)($_POST['loan_id'] ?? 0);
        try {
            $loan = $this->model->find($id);
            $this->model->approve($id, (int)Session::get('user_id'));
            $this->model->log((int)Session::get('user_id'), 'loan_approved',
                "Approved loan {$loan['loan_number']} — Shs " . number_format((float)$loan['loan_amount'], 2));
            // Stage 12-E: notify the operational team who acts next
            // (disburse) -- the same audience Stage 12-B's loan reminders
            // already use (LoanController::requireWriteAccess()).
            (new NotificationModel())->notifyRoles(
                ['admin', 'treasurer', 'loans_officer'],
                "Loan {$loan['loan_number']} approved",
                "Loan {$loan['loan_number']} (Shs " . number_format((float)$loan['loan_amount'], 2) . ") has been approved and can now be disbursed.",
                'success', 'loan', $id,
                ['loan_id' => $id, 'member_id' => $loan['member_id'] ?? null, 'action_url' => APP_URL . '/index.php?page=loan-view&id=' . $id],
                "loan_approved:{$id}"
            );
            Session::flash('success', "Loan {$loan['loan_number']} approved. It can now be disbursed.");
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=loan-view&id=' . $id);
    }

    public function reject(): void
    {
        $this->requireApproverAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loans');
            return;
        }

        $id     = (int)($_POST['loan_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        try {
            $loan = $this->model->find($id);
            $this->model->reject($id, (int)Session::get('user_id'), $reason);
            $this->model->log((int)Session::get('user_id'), 'loan_rejected',
                "Rejected loan {$loan['loan_number']} — Reason: {$reason}");
            (new NotificationModel())->notifyRoles(
                ['admin', 'treasurer', 'loans_officer'],
                "Loan {$loan['loan_number']} rejected",
                "Loan {$loan['loan_number']} was rejected: {$reason}",
                'critical', 'loan', $id,
                ['loan_id' => $id, 'member_id' => $loan['member_id'] ?? null, 'action_url' => APP_URL . '/index.php?page=loan-view&id=' . $id],
                "loan_rejected:{$id}"
            );
            Session::flash('success', "Loan {$loan['loan_number']} rejected and returned for correction.");
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=loan-view&id=' . $id);
    }

    public function disburse(): void
    {
        $this->requireApproverAccess();

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loans');
            return;
        }

        $id = (int)($_POST['loan_id'] ?? 0);
        try {
            $loan   = $this->model->find($id);
            $result = $this->model->disburse($id, (int)Session::get('user_id'));
            $this->model->log((int)Session::get('user_id'), 'loan_disbursed',
                "Disbursed loan {$loan['loan_number']} — journal entry {$result['entry_number']}");
            (new NotificationModel())->notifyRoles(
                ['admin', 'treasurer', 'loans_officer'],
                "Loan {$loan['loan_number']} disbursed",
                "Loan {$loan['loan_number']} has been disbursed (journal entry {$result['entry_number']}).",
                'success', 'loan', $id,
                ['loan_id' => $id, 'member_id' => $loan['member_id'] ?? null, 'action_url' => APP_URL . '/index.php?page=loan-view&id=' . $id],
                "loan_disbursed:{$id}"
            );
            Session::flash('success', "Loan {$loan['loan_number']} disbursed and posted as journal entry {$result['entry_number']}.");
        } catch (Exception $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=loan-view&id=' . $id);
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

    // ----------------------------------------------------------------
    // HELPERS
    // ----------------------------------------------------------------
    private function findOrAbort(int $id): array
    {
        $row = $this->model->find($id);
        if (!$row) $this->abort404();
        return $row;
    }

    private function abort404(): never
    {
        http_response_code(404);
        require VIEW_PATH . '/errors/404.php';
        exit;
    }

    private function redirectBack(?int $id): void
    {
        $this->redirect($id
            ? APP_URL . '/index.php?page=loan-edit&id=' . $id
            : APP_URL . '/index.php?page=loan-add'
        );
    }
}
