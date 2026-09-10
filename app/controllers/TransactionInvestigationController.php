<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/services/TransactionInvestigationService.php';

/**
 * TransactionInvestigationController — SA-4 (2026-09).
 *
 * READ-ONLY end to end. Thin: every method below just validates a fixed
 * shape of input (an integer ID, or a search term + a type from a fixed
 * allow-list), asks TransactionInvestigationService for evidence, and
 * renders it. No SQL, no table name, and no arbitrary field is ever
 * accepted from the request -- every route parameter is either an int ID
 * or a value checked against a hardcoded list in the service.
 */
class TransactionInvestigationController extends Controller
{
    private TransactionInvestigationService $service;

    public function __construct()
    {
        Session::requireAuth();
        // Same administrative convention as SA-1/SA-3: admin retains its
        // existing universal access, system_admin gets this new
        // read-only capability. No other role is granted access.
        if (!Session::hasRole(['admin', 'system_admin'])) {
            Session::flash('error', 'Access denied. System Administrator privileges required.');
            $this->redirect(APP_URL . '/index.php?page=dashboard');
            exit;
        }
        $this->service = new TransactionInvestigationService();
    }

    public function index(): void
    {
        $term = trim((string)($_GET['q'] ?? ''));
        $type = (string)($_GET['type'] ?? '');
        $results = $term !== '' ? $this->service->search($term, $type) : [];

        $this->render('investigation/index', [
            'pageTitle'   => 'Transaction Investigation — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Transaction Investigation', 'url' => ''],
            ],
            'term' => $term, 'type' => $type, 'results' => $results,
        ]);
    }

    private function renderTrace(array $panel): void
    {
        $this->render('investigation/trace', [
            'pageTitle'   => $panel['entity_type'] . ' ' . $panel['reference'] . ' — Investigation — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Transaction Investigation', 'url' => APP_URL . '/index.php?page=investigation'],
                ['label' => $panel['entity_type'] . ' ' . $panel['reference'], 'url' => ''],
            ],
            'panel' => $panel,
        ]);
    }

    private function intId(): int
    {
        return (int)($_GET['id'] ?? 0);
    }

    public function member(): void    { $this->renderTrace($this->service->traceMember($this->intId())); }
    public function savings(): void   { $this->renderTrace($this->service->traceSavingsTransaction($this->intId())); }
    public function loan(): void      { $this->renderTrace($this->service->traceLoan($this->intId())); }
    public function repayment(): void { $this->renderTrace($this->service->traceRepayment($this->intId())); }
    public function withdrawal(): void{ $this->renderTrace($this->service->traceWithdrawal($this->intId())); }
    public function fee(): void       { $this->renderTrace($this->service->traceFee($this->intId())); }
    public function journal(): void   { $this->renderTrace($this->service->traceJournalEntry($this->intId())); }

    public function orphans(): void
    {
        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = 25;
        $result = $this->service->listOrphanJournalReferences($limit, ($page - 1) * $limit);

        $this->render('investigation/orphans', [
            'pageTitle'   => 'Orphan Journal References — Investigation — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Transaction Investigation', 'url' => APP_URL . '/index.php?page=investigation'],
                ['label' => 'Orphan Journal References', 'url' => ''],
            ],
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'limit' => $limit,
        ]);
    }

    public function coverage(): void
    {
        $type = (string)($_GET['type'] ?? 'loan_repayments');
        if (!in_array($type, ['loan_repayments', 'savings'], true)) { $type = 'loan_repayments'; }
        $page = max(1, (int)($_GET['p'] ?? 1));
        $limit = 25;
        $result = $this->service->listCoverageGaps($type, $limit, ($page - 1) * $limit);

        $this->render('investigation/coverage', [
            'pageTitle'   => 'Transaction Coverage Gaps — Investigation — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Transaction Investigation', 'url' => APP_URL . '/index.php?page=investigation'],
                ['label' => 'Coverage Gaps', 'url' => ''],
            ],
            'type' => $type, 'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'limit' => $limit,
        ]);
    }
}
