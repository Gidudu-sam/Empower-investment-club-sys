<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/services/LoanProvisioningService.php';

/**
 * LoanProvisioningController — user-facing workflow for the
 * management-approved provisioning engine (PROV-001). Stage 21-D.
 *
 * This controller contains NO calculation logic of its own — every
 * business rule lives in LoanProvisioningService, which this class only
 * calls. Role separation follows Stage 20 §20 / Stage 21-D §10 exactly:
 * loans_officer may view and calculate (draft) only, never review,
 * finalize, reverse, or change policy. There is no policy-editing UI in
 * this stage (Stage 21-D §17) — PROV-001 is frozen and read-only here.
 */
class LoanProvisioningController extends Controller
{
    private LoanProvisioningService $svc;
    private PDO $db;

    public function __construct()
    {
        Session::requireAuth();
        // Stage 23: Vice Chairman added as deputy for Chairman's
        // review/finalize authority below, so needs view access too.
        // Secretary deliberately excluded -- not part of Secretary's
        // granted Stage 23 approval scope, and provisioning policy/
        // calculation logic is explicitly out of scope for this stage.
        if (!Session::hasRole(['admin', 'treasurer', 'chairman', 'vice_chairman', 'loans_officer', 'system_admin', 'viewer'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view loan provisioning.');
        }
        $this->svc = new LoanProvisioningService();
        $this->db  = Database::getInstance()->getConnection();
    }

    /** Backend-authoritative gates — the UI hiding a button is never the security boundary. */
    private function requireCalculateAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer', 'loans_officer', 'system_admin'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to calculate a provisioning run.');
        }
    }

    /** Stage 23: Vice Chairman added as deputy/alternate for Chairman,
     *  matching Chairman's own review scope exactly (neither calculates). */
    private function requireReviewAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer', 'chairman', 'vice_chairman', 'system_admin'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to review a provisioning run.');
        }
    }

    private function requireFinalizeAccess(): void
    {
        // Deliberately excludes loans_officer -- Stage 21-D §10's explicit
        // restriction: loans_officer must not gain finalize/reverse authority
        // merely because they can calculate. Enforced here, server-side,
        // not merely by hiding the button in the view.
        //
        // Stage 23: Vice Chairman added as deputy/alternate for Chairman.
        // The existing calculated_by !== finalizedBy maker-checker check in
        // LoanProvisioningService::finalizeRun() is untouched and still
        // applies regardless of role -- unchanged per Stage 23's explicit
        // prohibition on modifying provisioning policy/calculation logic.
        if (!Session::hasRole(['admin', 'treasurer', 'chairman', 'vice_chairman', 'system_admin'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to finalize a provisioning run.');
        }
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
        return $stored !== '' && hash_equals($stored, $token);
    }

    // ================================================================
    // INDEX
    // ================================================================
    public function index(): void
    {
        $statusFilter = $_GET['status'] ?? '';
        $periodFilter = (int)($_GET['accounting_period_id'] ?? 0);

        $sql = "SELECT r.*, p.version AS policy_version
                FROM loan_provisioning_runs r
                JOIN loan_provisioning_policies p ON p.id = r.policy_id
                WHERE 1=1";
        $params = [];
        if ($statusFilter !== '') { $sql .= " AND r.status=?"; $params[] = $statusFilter; }
        if ($periodFilter > 0)    { $sql .= " AND r.accounting_period_id=?"; $params[] = $periodFilter; }
        $sql .= " ORDER BY r.id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $periods = $this->db->query("SELECT id, name, start_date, end_date, status FROM accounting_periods ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);

        $this->render('loan-provisioning/index', [
            'pageTitle'   => 'Loan Provisioning',
            'breadcrumbs' => [['label' => 'Accounting'], ['label' => 'Loan Provisioning']],
            'runs'        => $runs,
            'periods'     => $periods,
            'statusFilter'=> $statusFilter,
            'periodFilter'=> $periodFilter,
            'canCalculate'=> Session::hasRole(['admin', 'treasurer', 'loans_officer', 'system_admin']),
            'success'     => Session::flash('success'),
            'error'       => Session::flash('error'),
        ]);
    }

    // ================================================================
    // CALCULATE (draft)
    // ================================================================
    public function calculateForm(): void
    {
        $this->requireCalculateAccess();
        // Approved policy: as-of date = accounting period end (Stage 20-B
        // Decision #14/#22). The form offers only open periods and derives
        // the as-of date from the selected period's own end_date -- it
        // never accepts an arbitrary date, so the UI cannot violate the
        // approved policy.
        $periods = $this->db->query("SELECT id, name, start_date, end_date FROM accounting_periods WHERE status='open' ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
        $policy = $this->svc->getActivePolicy();

        $this->render('loan-provisioning/calculate', [
            'pageTitle'   => 'Calculate Provisioning Run',
            'breadcrumbs' => [['label' => 'Accounting'], ['label' => 'Loan Provisioning', 'url' => APP_URL . '/index.php?page=loan-provisioning'], ['label' => 'Calculate']],
            'periods'     => $periods,
            'policy'      => $policy,
            'csrfToken'   => $this->getCsrf(),
        ]);
    }

    public function calculateStore(): void
    {
        $this->requireCalculateAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loan-provisioning-calculate');
            return;
        }

        $periodId = (int)($_POST['accounting_period_id'] ?? 0);
        $period = $this->db->prepare("SELECT id, end_date, status FROM accounting_periods WHERE id=?");
        $period->execute([$periodId]);
        $period = $period->fetch(PDO::FETCH_ASSOC);
        if (!$period) {
            Session::flash('error', 'Please select a valid accounting period.');
            $this->redirect(APP_URL . '/index.php?page=loan-provisioning-calculate');
            return;
        }
        // As-of date is ALWAYS the selected period's own end date -- never
        // read from a free-text/date-picker field, per the approved policy.
        $asOfDate = $period['end_date'];

        try {
            $runId = $this->svc->calculateRun($periodId, $asOfDate, (int)Session::get('user_id'));
            Session::flash('success', 'Provisioning run calculated. Review the figures before finalizing.');
            $this->redirect(APP_URL . '/index.php?page=loan-provisioning-view&id=' . $runId);
        } catch (Throwable $e) {
            Session::flash('error', 'Calculation failed: ' . $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=loan-provisioning-calculate');
        }
    }

    // ================================================================
    // VIEW (frozen snapshot — never recalculated from live tables)
    // ================================================================
    public function view(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        try {
            $run = $this->svc->getRun($id);
        } catch (InvalidArgumentException $e) {
            http_response_code(404);
            die('Provisioning run not found.');
        }
        $details = $this->svc->getRunDetails($id);

        $policy = $this->db->prepare("SELECT version FROM loan_provisioning_policies WHERE id=?");
        $policy->execute([$run['policy_id']]);
        $policyVersion = $policy->fetchColumn();

        $period = $this->db->prepare("SELECT name FROM accounting_periods WHERE id=?");
        $period->execute([$run['accounting_period_id']]);
        $periodName = $period->fetchColumn();

        $userNames = function (?int $id) {
            if (!$id) return null;
            $s = $this->db->prepare("SELECT full_name FROM users WHERE id=?");
            $s->execute([$id]);
            return $s->fetchColumn() ?: ('User #' . $id);
        };

        $this->render('loan-provisioning/view', [
            'pageTitle'      => $run['run_number'] . ' — Provisioning Run',
            'breadcrumbs'    => [['label' => 'Accounting'], ['label' => 'Loan Provisioning', 'url' => APP_URL . '/index.php?page=loan-provisioning'], ['label' => $run['run_number']]],
            'run'            => $run,
            'details'        => $details,
            'policyVersion'  => $policyVersion,
            'periodName'     => $periodName,
            'calculatedByName' => $userNames((int)$run['calculated_by'] ?: null),
            'reviewedByName'   => $userNames($run['reviewed_by'] ? (int)$run['reviewed_by'] : null),
            'finalizedByName'  => $userNames($run['finalized_by'] ? (int)$run['finalized_by'] : null),
            // Stage 23: matches requireReviewAccess()/requireFinalizeAccess() exactly.
            'canReview'      => Session::hasRole(['admin', 'treasurer', 'chairman', 'vice_chairman', 'system_admin']),
            'canFinalize'    => Session::hasRole(['admin', 'treasurer', 'chairman', 'vice_chairman', 'system_admin']),
            'currentUserId'  => (int)Session::get('user_id'),
            'csrfToken'      => $this->getCsrf(),
        ]);
    }

    // ================================================================
    // REVIEW
    // ================================================================
    public function review(): void
    {
        $this->requireReviewAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loan-provisioning');
            return;
        }
        $id = (int)($_POST['run_id'] ?? 0);
        try {
            $this->svc->reviewRun($id, (int)Session::get('user_id'));
            Session::flash('success', 'Run marked as reviewed.');
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=loan-provisioning-view&id=' . $id);
    }

    // ================================================================
    // FINALIZE
    // ================================================================
    public function finalize(): void
    {
        $this->requireFinalizeAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loan-provisioning');
            return;
        }
        $id = (int)($_POST['run_id'] ?? 0);
        try {
            $result = $this->svc->finalizeRun($id, (int)Session::get('user_id'));
            $msg = $result['journal_entry_id']
                ? "Run finalized and posted (journal entry #{$result['journal_entry_id']}, delta " . number_format($result['delta'], 2) . ').'
                : 'Run finalized. Delta was zero — no journal entry was required.';
            Session::flash('success', $msg);
        } catch (Throwable $e) {
            Session::flash('error', 'Finalization failed: ' . $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=loan-provisioning-view&id=' . $id);
    }

    // ================================================================
    // CORRECTION (forward-only — never edits the original)
    // ================================================================
    public function correction(): void
    {
        $this->requireCalculateAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loan-provisioning');
            return;
        }
        $correctsRunId = (int)($_POST['run_id'] ?? 0);
        try {
            $original = $this->svc->getRun($correctsRunId);
            if ($original['status'] !== 'finalized') {
                throw new RuntimeException('Only a finalized run can be corrected.');
            }
            $newRunId = $this->svc->createCorrectionRun(
                $correctsRunId,
                (int)$original['accounting_period_id'],
                $original['as_of_date'],
                (int)Session::get('user_id')
            );
            Session::flash('success', 'A new forward correction run was created. The original finalized run remains unchanged.');
            $this->redirect(APP_URL . '/index.php?page=loan-provisioning-view&id=' . $newRunId);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=loan-provisioning-view&id=' . $correctsRunId);
        }
    }

    // ================================================================
    // REPORTS — read exclusively from the frozen snapshot tables
    // (loan_provisioning_runs / loan_provisioning_run_details). Never
    // recalculates from loans/loan_installments/loan_repayments.
    // ================================================================

    public function reportSummary(): void
    {
        $runs = $this->db->query(
            "SELECT r.*, p.version AS policy_version
             FROM loan_provisioning_runs r
             JOIN loan_provisioning_policies p ON p.id = r.policy_id
             WHERE r.status='finalized' ORDER BY r.finalized_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->render('loan-provisioning/report-summary', [
            'pageTitle'   => 'Provisioning Summary',
            'breadcrumbs' => [['label' => 'Accounting'], ['label' => 'Loan Provisioning', 'url' => APP_URL . '/index.php?page=loan-provisioning'], ['label' => 'Summary Report']],
            'runs'        => $runs,
        ]);
    }

    public function reportByBucket(): void
    {
        $runId = (int)($_GET['run_id'] ?? 0);
        $run = $runId ? $this->svc->getRun($runId) : null;
        $rows = [];
        if ($runId) {
            $stmt = $this->db->prepare(
                "SELECT bucket_name, bucket_min_days, bucket_max_days, applied_rate,
                        COUNT(*) AS loan_count, SUM(unpaid_principal_exposure) AS total_exposure,
                        SUM(required_provision) AS total_required
                 FROM loan_provisioning_run_details
                 WHERE run_id=? AND exclusion_reason IS NULL
                 GROUP BY bucket_name, bucket_min_days, bucket_max_days, applied_rate
                 ORDER BY bucket_min_days"
            );
            $stmt->execute([$runId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $finalizedRuns = $this->db->query("SELECT id, run_number, as_of_date FROM loan_provisioning_runs WHERE status='finalized' ORDER BY finalized_at DESC")->fetchAll(PDO::FETCH_ASSOC);

        $this->render('loan-provisioning/report-by-bucket', [
            'pageTitle'   => 'Provisioning by Aging Bucket',
            'breadcrumbs' => [['label' => 'Accounting'], ['label' => 'Loan Provisioning', 'url' => APP_URL . '/index.php?page=loan-provisioning'], ['label' => 'By Aging Bucket']],
            'run'         => $run,
            'runId'       => $runId,
            'rows'        => $rows,
            'finalizedRuns' => $finalizedRuns,
        ]);
    }

    public function reportLoanLevel(): void
    {
        $runId = (int)($_GET['run_id'] ?? 0);
        $run = $runId ? $this->svc->getRun($runId) : null;
        $details = $runId ? $this->svc->getRunDetails($runId) : [];
        $finalizedRuns = $this->db->query("SELECT id, run_number, as_of_date FROM loan_provisioning_runs WHERE status='finalized' ORDER BY finalized_at DESC")->fetchAll(PDO::FETCH_ASSOC);

        $this->render('loan-provisioning/report-loan-level', [
            'pageTitle'   => 'Loan-Level Provisioning',
            'breadcrumbs' => [['label' => 'Accounting'], ['label' => 'Loan Provisioning', 'url' => APP_URL . '/index.php?page=loan-provisioning'], ['label' => 'Loan-Level']],
            'run'         => $run,
            'runId'       => $runId,
            'details'     => $details,
            'finalizedRuns' => $finalizedRuns,
        ]);
    }

    public function reportMovement(): void
    {
        $stmt = $this->db->query(
            "SELECT r.id, r.run_number, r.as_of_date, r.total_previous_provision, r.total_required_provision,
                    r.total_delta, r.journal_entry_id, r.finalized_at
             FROM loan_provisioning_runs r
             WHERE r.status='finalized'
             ORDER BY r.finalized_at ASC"
        );
        $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->render('loan-provisioning/report-movement', [
            'pageTitle'   => 'Provisioning Movement',
            'breadcrumbs' => [['label' => 'Accounting'], ['label' => 'Loan Provisioning', 'url' => APP_URL . '/index.php?page=loan-provisioning'], ['label' => 'Movement']],
            'runs'        => $runs,
        ]);
    }
}
