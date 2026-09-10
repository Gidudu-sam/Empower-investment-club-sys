<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/RepaymentModel.php';
require_once APP_PATH  . '/models/LoanModel.php';
require_once APP_PATH  . '/models/MemberModel.php';

/**
 * RepaymentController
 *
 * Routes:
 *   repayments          → index()
 *   repayment-add       → add()
 *   repayment-view      → view()
 *   repayment-receipt   → receipt()
 *   repayment-loan      → loanRepayments()
 *   repayment-report    → report()
 *   repayment-loan-search → loanSearch()   [AJAX]
 */
class RepaymentController extends Controller
{
    private RepaymentModel $model;
    private LoanModel      $loanModel;
    private MemberModel    $memberModel;

    public function __construct()
    {
        $this->model       = new RepaymentModel();
        $this->loanModel   = new LoanModel();
        $this->memberModel = new MemberModel();
        $this->loanModel->syncOverdueStatus();
    }

    // ----------------------------------------------------------------
    // LIST
    // ----------------------------------------------------------------
    public function index(): void
    {
        Session::requireAuth();

        $term     = trim($_GET['search']    ?? '');
        $method   = trim($_GET['method']    ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo   = trim($_GET['date_to']   ?? '');
        $page     = max(1, (int)($_GET['p'] ?? 1));

        $result = $this->model->search($term, $method, $dateFrom, $dateTo, 0, 0, $page, 15);

        $this->render('repayments/index', [
            'pageTitle'    => 'Repayments — ' . APP_NAME,
            'breadcrumbs'  => [['label' => 'Loan Repayments']],
            'repayments'   => $result['rows'],
            'total'        => $result['total'],
            'pages'        => $result['pages'],
            'currentPage'  => $page,
            'totalPaid'    => $result['totalPaid'],
            'search'       => $term,
            'method'       => $method,
            'dateFrom'     => $dateFrom,
            'dateTo'       => $dateTo,
            'todayTotal'   => $this->model->todayCollections(),
            'monthTotal'   => $this->model->monthCollections(),
            'success'      => Session::flash('success'),
            'error'        => Session::flash('error'),
            'csrfToken'    => $this->getCsrf(),
        ], 'main');
    }

    // ----------------------------------------------------------------
    // ADD
    // ----------------------------------------------------------------
    public function add(): void
    {
        Session::requireAuth();

        // Stage 1 security remediation: recording a repayment is an explicit
        // cashier capability in the intended role model (front-desk collection).
        // Six-role model: Loans Officer also records repayments (monitoring
        // the lending process end-to-end), matching the proposed matrix.
        // Payment-recording policy alignment: Office Administrator also
        // collects repayment information received at the office.
        if (!Session::hasRole(['admin', 'treasurer', 'cashier', 'loans_officer', 'office_admin'])) {
            Session::flash('error', 'Access denied. You do not have permission to record a repayment.');
            $this->redirect(APP_URL . '/index.php?page=repayments');
            return;
        }

        if ($this->isPost()) {
            $this->handleSave();
            return;
        }

        // Pre-load loan if loan_id supplied
        $preLoan = null;
        $preId   = (int)($_GET['loan_id'] ?? 0);
        $outstandingPenalty = 0.0;
        if ($preId > 0) {
            $preLoan = $this->loanModel->findWithDetails($preId);
            $outstandingPenalty = $this->model->getOutstandingPenalty($preId);
        }

        $this->render('repayments/form', [
            'pageTitle'        => 'Record Repayment — ' . APP_NAME,
            'breadcrumbs'      => [
                ['label' => 'Repayments', 'url' => APP_URL . '/index.php?page=repayments'],
                ['label' => 'Record Repayment'],
            ],
            'formAction'       => APP_URL . '/index.php?page=repayment-add',
            'repayment'        => Session::flash('form_old') ?? [],
            'errors'           => Session::flash('form_errors') ?? [],
            'repaymentNumber'  => $this->model->generateRepaymentNumber(),
            'csrfToken'        => $this->getCsrf(),
            'preLoan'          => $preLoan,
            'outstandingPenalty' => $outstandingPenalty,
        ], 'main');
    }

    // ----------------------------------------------------------------
    // VIEW
    // ----------------------------------------------------------------
    public function view(): void
    {
        Session::requireAuth();

        $id = (int)($_GET['id'] ?? 0);
        $r  = $this->model->findWithDetails($id);
        if (!$r) $this->abort404();

        // Most-recent-first for the "Recent Transactions" table -- forLoan()
        // itself stays ASC (its other caller, loanRepayments(), lists a full
        // ledger oldest-first), so reverse just for this view.
        $recentTransactions = array_reverse($this->model->forLoan((int)$r['loan_id']));

        $this->render('repayments/view', [
            'pageTitle'   => $r['repayment_number'] . ' — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Repayments', 'url' => APP_URL . '/index.php?page=repayments'],
                ['label' => $r['repayment_number']],
            ],
            'repayment'          => $r,
            'recentTransactions' => $recentTransactions,
            'success'            => Session::flash('success'),
        ], 'main');
    }

    // ----------------------------------------------------------------
    // RECEIPT (printable)
    // ----------------------------------------------------------------
    public function receipt(): void
    {
        Session::requireAuth();

        $id = (int)($_GET['id'] ?? 0);
        $r  = $this->model->findWithDetails($id);
        if (!$r) $this->abort404();

        $this->model->log((int)Session::get('user_id'), 'receipt_printed',
            "Printed repayment receipt {$r['repayment_number']}");

        $this->render('repayments/receipt', ['repayment' => $r], null);
    }

    // ----------------------------------------------------------------
    // LOAN REPAYMENT HISTORY
    // ----------------------------------------------------------------
    public function loanRepayments(): void
    {
        Session::requireAuth();

        $loanId = (int)($_GET['loan_id'] ?? 0);
        $loan   = $this->loanModel->findWithDetails($loanId);
        if (!$loan) $this->abort404();

        // Recent-first, matching this same controller's own "Recent
        // Transactions" panel (view(), a few lines above) -- forLoan()
        // itself stays chronological for the formal statement documents.
        $repayments = array_reverse($this->model->forLoan($loanId));
        $totalPaid  = $this->model->totalPaidForLoan($loanId);

        $this->render('repayments/loan-repayments', [
            'pageTitle'   => 'Repayments — ' . $loan['loan_number'],
            'breadcrumbs' => [
                ['label' => 'Loans', 'url' => APP_URL . '/index.php?page=loans'],
                ['label' => $loan['loan_number'],
                 'url'   => APP_URL . '/index.php?page=loan-view&id=' . $loanId],
                ['label' => 'Repayments'],
            ],
            'loan'       => $loan,
            'repayments' => $repayments,
            'totalPaid'  => $totalPaid,
        ], 'main');
    }

    // ----------------------------------------------------------------
    // REPORT
    // ----------------------------------------------------------------
    public function report(): void
    {
        Session::requireAuth();

        $type     = trim($_GET['type']  ?? 'monthly');
        $year     = trim($_GET['year']  ?? date('Y'));
        $month    = trim($_GET['month'] ?? date('m'));
        $dateFrom = ''; $dateTo = '';

        if ($type === 'daily') {
            $dateFrom = $dateTo = trim($_GET['date'] ?? date('Y-m-d'));
        } elseif ($type === 'weekly') {
            $dateFrom = date('Y-m-d', strtotime('monday this week'));
            $dateTo   = date('Y-m-d', strtotime('sunday this week'));
        } elseif ($type === 'monthly') {
            $dateFrom = date('Y-m-01', mktime(0,0,0,(int)$month,1,(int)$year));
            $dateTo   = date('Y-m-t',  mktime(0,0,0,(int)$month,1,(int)$year));
        } elseif ($type === 'annual') {
            $dateFrom = "{$year}-01-01";
            $dateTo   = "{$year}-12-31";
        }

        $result = $this->model->search('', '', $dateFrom, $dateTo, 0, 0, 1, 9999);

        $this->render('repayments/report', [
            'pageTitle'   => 'Repayments Report — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Repayments', 'url' => APP_URL . '/index.php?page=repayments'],
                ['label' => 'Report'],
            ],
            'repayments'  => $result['rows'],
            'totalAmount' => $result['totalPaid'],
            'type'        => $type,
            'year'        => $year,
            'month'       => $month,
            'dateFrom'    => $dateFrom,
            'dateTo'      => $dateTo,
        ], 'main');
    }

    // ----------------------------------------------------------------
    // AJAX — search active/overdue loans for repayment form
    // ----------------------------------------------------------------
    public function loanSearch(): void
    {
        Session::requireAuth();

        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { $this->json(['loans' => []]); return; }

        // Search only active/overdue loans
        $result = $this->loanModel->search($q, '', '', 0, 1, 20);
        $out = [];
        foreach ($result['rows'] as $l) {
            if (in_array($l['status'], ['active','overdue'])) {
                $out[] = [
                    'id'            => (int)$l['id'],
                    'loan_number'   => $l['loan_number'],
                    'member_id'     => (int)$l['member_id'],
                    'member_name'   => $l['first_name'] . ' ' . $l['last_name'],
                    'member_number' => $l['member_number'],
                    'loan_amount'   => (float)$l['loan_amount'],
                    'total_payable' => (float)$l['total_payable'],
                    'outstanding'   => (float)$l['outstanding'],
                    'outstanding_penalty' => $this->model->getOutstandingPenalty((int)$l['id']),
                    'due_date'      => $l['due_date'],
                    'status'        => $l['status'],
                    'loan_type_id'  => (int)($l['loan_type_id'] ?? 1),
                    'loan_type_name'=> $l['loan_type_name'] ?? 'Normal Loan',
                ];
            }
        }
        $this->json(['loans' => $out]);
    }

    // ----------------------------------------------------------------
    // DELETE PAYMENT
    // ----------------------------------------------------------------
    public function delete(): void
    {
        Session::requireAuth();
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Only administrators can delete payments.');
            $this->redirect(APP_URL . '/index.php?page=repayments');
            return;
        }

        // Stage 13-C (H-1): was GET-triggered with no CSRF check.
        if (!$this->isPost()) {
            Session::flash('error', 'Invalid request method.');
            $this->redirect(APP_URL . '/index.php?page=repayments');
            return;
        }
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=repayments');
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        $repayment = $this->model->findWithDetails($id);
        if (!$repayment) {
            Session::flash('error', 'Payment not found.');
            $this->redirect(APP_URL . '/index.php?page=repayments');
            return;
        }

        $userId = (int)Session::get('user_id');
        try {
            // Stage 9: RepaymentModel::delete() now also reverses any posted
            // journal entry, atomically with the loan-balance/installment
            // reversal it already did (no second journal-writing mechanism
            // is introduced -- it still goes through JournalService::reverse()).
            $deleted = $this->model->delete($id, $userId);
            if ($deleted) {
                $this->model->log($userId, 'repayment_deleted',
                    "Deleted payment {$repayment['repayment_number']} — Shs " . number_format((float)$repayment['amount_paid'], 2));
                Session::flash('success', "Payment {$repayment['repayment_number']} deleted and loan balance reversed.");
            } else {
                Session::flash('error', 'Could not delete the payment record.');
            }
        } catch (Throwable $e) {
            Session::flash('error', 'Failed to delete payment: ' . $e->getMessage());
        }

        $this->redirect(APP_URL . '/index.php?page=repayments');
    }

    // ----------------------------------------------------------------
    // SAVE HANDLER
    // ----------------------------------------------------------------
    private function handleSave(): void
    {
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=repayment-add');
            return;
        }

        $input  = $this->collectInput();
        $errors = $this->validate($input);

        if ($errors) {
            Session::flash('form_errors', $errors);
            Session::flash('form_old',    $input);
            $this->redirect(APP_URL . '/index.php?page=repayment-add' .
                ($input['loan_id'] > 0 ? '&loan_id=' . $input['loan_id'] : ''));
            return;
        }

        $userId = (int)Session::get('user_id');
        $input['repayment_number'] = $this->model->generateRepaymentNumber();
        $input['received_by']      = $userId;

        $paymentType = $input['payment_type'] ?? 'installment';
        $newId = false;

        // Stage 9: repayment recording now posts a journal entry inside the
        // same transaction (RepaymentModel::postRepaymentJournal()) and
        // throws rather than silently swallowing a posting failure -- catch
        // here so the operator sees a specific reason instead of a fatal.
        try {
            switch ($paymentType) {
                case 'interest':
                    $newId = $this->model->recordInterestPayment($input);
                    break;
                case 'weekly_savings':
                    $newId = $this->model->recordWeeklySavings($input);
                    break;
                case 'principal':
                    $newId = $this->model->recordPrincipalPayment($input);
                    break;
                default: // installment, settlement
                    $newId = $this->model->recordRepayment($input);
                    break;
            }
        } catch (Throwable $e) {
            Session::flash('error', 'Failed to record payment: ' . $e->getMessage());
            Session::flash('form_old', $input);
            $this->redirect(APP_URL . '/index.php?page=repayment-add' .
                ($input['loan_id'] > 0 ? '&loan_id=' . $input['loan_id'] : ''));
            return;
        }

        if ($newId) {
            $loan = $this->loanModel->find($input['loan_id']);
            $notificationModel = new NotificationModel();
            // Stage 12-E: placed exactly where the existing activity_logs
            // calls already sit -- strictly after recordRepayment() et al.
            // have already committed (including their own journal posting),
            // never inside that transaction. Same operational audience as
            // loan approved/disbursed (Stage 12-B's requireWriteAccess()).
            $notificationModel->notifyRoles(
                ['admin', 'treasurer', 'loans_officer'],
                "Repayment recorded — {$loan['loan_number']}",
                "Repayment {$input['repayment_number']} ({$paymentType}) of Shs " . number_format($input['amount_paid'], 2) . " recorded for loan {$loan['loan_number']}.",
                'success', 'repayment', $newId,
                ['loan_id' => (int)$input['loan_id'], 'member_id' => $loan['member_id'] ?? null, 'action_url' => APP_URL . '/index.php?page=repayment-view&id=' . $newId],
                "repayment_recorded:{$newId}"
            );
            if (($loan['status'] ?? '') === 'completed') {
                $this->model->log($userId, 'loan_completed',
                    "Loan {$loan['loan_number']} completed via repayment {$input['repayment_number']}");
                $notificationModel->notifyRoles(
                    ['admin', 'treasurer', 'loans_officer'],
                    "Loan {$loan['loan_number']} fully repaid",
                    "Loan {$loan['loan_number']} has been fully repaid and is now completed.",
                    'success', 'loan', (int)$input['loan_id'],
                    ['loan_id' => (int)$input['loan_id'], 'member_id' => $loan['member_id'] ?? null, 'priority' => 'high', 'action_url' => APP_URL . '/index.php?page=loan-view&id=' . $input['loan_id']],
                    "loan_completed:{$input['loan_id']}"
                );
            }
            $this->model->log($userId, 'repayment_recorded',
                "Recorded {$input['repayment_number']} ({$paymentType}) — Shs " . number_format($input['amount_paid'], 2));
            Session::flash('success', "Payment {$input['repayment_number']} recorded successfully.");
            $this->redirect(APP_URL . '/index.php?page=repayment-view&id=' . $newId);
        } else {
            Session::flash('error', 'Failed to record payment. Please try again.');
            Session::flash('form_old', $input);
            $this->redirect(APP_URL . '/index.php?page=repayment-add' .
                ($input['loan_id'] > 0 ? '&loan_id=' . $input['loan_id'] : ''));
        }
    }

    // ----------------------------------------------------------------
    // INPUT + VALIDATION
    // ----------------------------------------------------------------
    private function collectInput(): array
    {
        $s = fn(string $k, string $d='') => $this->sanitize($_POST[$k] ?? $d);
        return [
            'loan_id'          => (int)($_POST['loan_id'] ?? 0),
            'member_id'        => (int)($_POST['member_id'] ?? 0),
            'payment_type'     => in_array($_POST['payment_type'] ?? '', ['installment','interest','weekly_savings','principal','settlement'])
                                    ? $_POST['payment_type'] : 'installment',
            'payment_date'     => $s('payment_date', date('Y-m-d')),
            'amount_paid'      => (float)($_POST['amount_paid'] ?? 0),
            'penalty_paid'     => (float)($_POST['penalty_paid'] ?? 0),
            'payment_method'   => $s('payment_method', 'Cash'),
            'reference_number' => $s('reference_number') ?: null,
            'notes'            => $s('notes') ?: null,
            'week_covered'     => $s('week_covered') ?: null,
        ];
    }

    private function validate(array $d): array
    {
        $e = [];

        if ($d['loan_id'] < 1) {
            $e['loan_id'] = 'Please select a loan.';
        } else {
            $loan = $this->loanModel->find($d['loan_id']);
            if (!$loan) {
                $e['loan_id'] = 'Loan not found.';
            } elseif ($loan['status'] === 'completed') {
                $e['loan_id'] = 'This loan is already fully paid.';
            } elseif ($d['amount_paid'] > (float)$loan['outstanding']) {
                $e['amount_paid'] = 'Payment of Shs ' . number_format($d['amount_paid'], 2) .
                    ' exceeds the outstanding balance of Shs ' .
                    number_format($loan['outstanding'], 2) . '.';
            }
        }

        if ($d['amount_paid'] <= 0) {
            $e['amount_paid'] = 'Amount must be greater than zero.';
        }

        if (empty($d['payment_date']) || !DateTime::createFromFormat('Y-m-d', $d['payment_date'])) {
            $e['payment_date'] = 'Enter a valid payment date.';
        }

        if (!in_array($d['payment_method'], ['Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other'], true)) {
            $e['payment_method'] = 'Select a valid payment method.';
        }

        // Penalty collection (Stage 17 Part C) -- this is a nice-error-message
        // pre-check only; RepaymentModel::recordRepayment() independently
        // re-validates the outstanding amount server-side, under lock, and
        // never trusts this or any client-submitted figure.
        if ($d['penalty_paid'] < 0) {
            $e['penalty_paid'] = 'Penalty payment cannot be negative.';
        } elseif ($d['penalty_paid'] > 0) {
            if (!in_array($d['payment_type'], ['installment', 'settlement'], true)) {
                $e['penalty_paid'] = 'Penalty payment is only supported for installment or settlement payments.';
            } elseif ($d['penalty_paid'] > $d['amount_paid']) {
                $e['penalty_paid'] = 'Penalty payment cannot exceed the total amount paid.';
            } elseif ($d['loan_id'] > 0) {
                $outstanding = $this->model->getOutstandingPenalty($d['loan_id']);
                if ($d['penalty_paid'] > $outstanding + 0.01) {
                    $e['penalty_paid'] = 'Penalty payment of Shs ' . number_format($d['penalty_paid'], 2) .
                        ' exceeds the outstanding penalty of Shs ' . number_format($outstanding, 2) . '.';
                }
            }
        }

        return $e;
    }

    // ----------------------------------------------------------------
    // CSRF
    // ----------------------------------------------------------------
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
