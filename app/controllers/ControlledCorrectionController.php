<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/services/ControlledCorrectionService.php';
require_once APP_PATH  . '/services/TransactionInvestigationService.php';
require_once APP_PATH  . '/models/SettingsModel.php';

/**
 * ControlledCorrectionController — SA-5 (2026-09).
 *
 * Five-step flow, no step skippable via URL manipulation alone:
 *   index         GET  -- list of past corrections + entry point
 *   prepare       GET  -- evidence + reason form for one journal entry
 *                         (?journal_id=... -- normally reached from an
 *                         SA-4 journal trace page's "Prepare Correction" link)
 *   store         POST + CSRF -- validates, creates a 'prepared' row, never
 *                         touches accounting data
 *   review        GET  -- shows the prepared correction for final human
 *                         confirmation (re-fetches fresh evidence)
 *   execute       POST + CSRF -- the ONLY action that mutates financial
 *                         data; idempotent (a repeat call returns the
 *                         existing result rather than re-running)
 *   result        GET  -- permanent, re-viewable outcome page
 *   cancel        POST + CSRF -- abandons a still-'prepared' correction
 *
 * No action in this controller accepts a table name, SQL fragment, or
 * arbitrary field from the request -- only integer IDs and a free-text
 * reason, exactly as the SA-5 brief requires.
 */
class ControlledCorrectionController extends Controller
{
    private ControlledCorrectionService $service;
    private SettingsModel $settings;

    public function __construct()
    {
        Session::requireAuth();
        // Same administrative convention as SA-1/SA-3/SA-4: admin retains
        // its existing universal access, system_admin gets this new
        // capability. No other role is granted access -- and unlike
        // SA-1's settings gates, this one is never reused for anything
        // else, since it is the one place in the app allowed to mutate
        // financial history.
        if (!Session::hasRole(['admin', 'system_admin'])) {
            Session::flash('error', 'Access denied. System Administrator privileges required.');
            $this->redirect(APP_URL . '/index.php?page=dashboard');
            exit;
        }
        $this->service  = new ControlledCorrectionService();
        $this->settings = new SettingsModel();
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

    public function index(): void
    {
        $this->render('corrections/index', [
            'pageTitle'   => 'Controlled Corrections — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Controlled Corrections', 'url' => ''],
            ],
            'types' => $this->service->correctionTypes(),
            'recent' => $this->service->recent(50),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function prepare(): void
    {
        $journalId = (int)($_GET['journal_id'] ?? 0);
        $eval = $this->service->evaluateReversalEligibility($journalId);

        $this->render('corrections/prepare', [
            'pageTitle'   => 'Prepare Correction — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Controlled Corrections', 'url' => APP_URL . '/index.php?page=corrections'],
                ['label' => 'Prepare', 'url' => ''],
            ],
            'journalId' => $journalId, 'eval' => $eval, 'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function store(): void
    {
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=corrections');
            return;
        }
        $journalId = (int)($_POST['journal_id'] ?? 0);
        $reason = (string)($_POST['reason'] ?? '');

        try {
            $result = $this->service->prepareCorrection($journalId, (int)Session::get('user_id'), $reason);
            $this->settings->log((int)Session::get('user_id'), 'correction_prepared', "Prepared correction {$result['correction_number']} for journal entry #{$journalId}");
            Session::flash('success', "Correction {$result['correction_number']} prepared. Review the evidence before confirming.");
            $this->redirect(APP_URL . '/index.php?page=correction-review&id=' . $result['id']);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=correction-prepare&journal_id=' . $journalId);
        }
    }

    public function review(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $correction = $this->service->find($id);
        if (!$correction) {
            Session::flash('error', 'Correction not found.');
            $this->redirect(APP_URL . '/index.php?page=corrections');
            return;
        }
        if ($correction['status'] === 'executed') {
            $this->redirect(APP_URL . '/index.php?page=correction-result&id=' . $id);
            return;
        }

        // Re-fetch fresh evidence for the review page -- never rely on
        // the snapshot captured at prepare time for what is DISPLAYED
        // (execute() independently re-validates it again before mutating).
        $eval = $this->service->evaluateReversalEligibility((int)$correction['target_entity_id']);

        $this->render('corrections/review', [
            'pageTitle'   => "Review {$correction['correction_number']} — " . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Controlled Corrections', 'url' => APP_URL . '/index.php?page=corrections'],
                ['label' => 'Review ' . $correction['correction_number'], 'url' => ''],
            ],
            'correction' => $correction, 'eval' => $eval, 'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function execute(): void
    {
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=corrections');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if (empty($_POST['confirm'])) {
            Session::flash('error', 'You must explicitly confirm before a correction can execute.');
            $this->redirect(APP_URL . '/index.php?page=correction-review&id=' . $id);
            return;
        }

        try {
            $outcome = $this->service->executeCorrection($id, (int)Session::get('user_id'));
            $this->settings->log((int)Session::get('user_id'), 'correction_executed', "Executed correction #{$id} (" . ($outcome['correction']['correction_number'] ?? '') . ')');
            if ($outcome['status'] === 'already_executed') {
                Session::flash('success', 'This correction was already executed -- showing the existing result.');
            } else {
                Session::flash('success', 'Correction executed successfully.');
            }
            $this->redirect(APP_URL . '/index.php?page=correction-result&id=' . $id);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=correction-review&id=' . $id);
        }
    }

    public function result(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $correction = $this->service->find($id);
        if (!$correction) {
            Session::flash('error', 'Correction not found.');
            $this->redirect(APP_URL . '/index.php?page=corrections');
            return;
        }

        $resultingJournal = null;
        if ($correction['resulting_journal_entry_id']) {
            $svc = new TransactionInvestigationService();
            $resultingJournal = $svc->traceJournalEntry((int)$correction['resulting_journal_entry_id']);
        }
        if ($correction['executed_by']) {
            $userModel = new UserModel();
            $executor = $userModel->find((int)$correction['executed_by']);
            $correction['executed_by'] = $executor['full_name'] ?? ('user #' . $correction['executed_by']);
        }

        $this->render('corrections/result', [
            'pageTitle'   => "{$correction['correction_number']} Result — " . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Controlled Corrections', 'url' => APP_URL . '/index.php?page=corrections'],
                ['label' => $correction['correction_number'], 'url' => ''],
            ],
            'correction' => $correction, 'resultingJournal' => $resultingJournal,
        ]);
    }

    public function cancel(): void
    {
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=corrections');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        try {
            $this->service->cancel($id, (int)Session::get('user_id'));
            $this->settings->log((int)Session::get('user_id'), 'correction_cancelled', "Cancelled correction #{$id}");
            Session::flash('success', 'Correction cancelled.');
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=corrections');
    }
}
