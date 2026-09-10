<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/services/SystemIntegrityService.php';
require_once APP_PATH  . '/models/SettingsModel.php';

/**
 * SystemIntegrityController — SA-3 (2026-09).
 *
 * Thin controller: all diagnostic logic lives in SystemIntegrityService.
 * READ-ONLY end to end -- no action in this controller writes, repairs,
 * reverses, or posts anything. There is no generic "run arbitrary SQL"
 * endpoint and no table/SQL fragment is ever accepted from the request;
 * detail() only accepts a fixed "code" identifying one of the service's
 * own pre-defined checks.
 */
class SystemIntegrityController extends Controller
{
    private SystemIntegrityService $service;
    private SettingsModel $settings;

    public function __construct()
    {
        Session::requireAuth();
        // Same convention as every other SA-1/SA-2 System Administrator
        // area: admin retains its existing universal administrative
        // access, system_admin gets this new capability. No other role is
        // granted access here, matching the brief's explicit exclusion
        // list (treasurer/cashier/chairman/office_admin/loans_officer/
        // member/viewer all stay out).
        if (!Session::hasRole(['admin', 'system_admin'])) {
            Session::flash('error', 'Access denied. System Administrator privileges required.');
            $this->redirect(APP_URL . '/index.php?page=dashboard');
            exit;
        }
        $this->service  = new SystemIntegrityService();
        $this->settings = new SettingsModel();
    }

    public function index(): void
    {
        $results = $this->service->runAll();

        // Matches the existing convention (SettingsModel::log() ->
        // activity_logs) already used by every other administrative
        // action in this project. A read-only diagnostic run does not
        // need a row per SELECT, but "an integrity check was run, by
        // whom, when" is the kind of administrative action this
        // project's audit trail already records for comparable actions
        // (e.g. 'database_backup'). No SQL, no data values, no secrets.
        $this->settings->log((int)Session::get('user_id'), 'system_integrity_check', 'Ran the System Integrity diagnostic suite');

        $this->render('system-integrity/index', [
            'pageTitle'   => 'System Integrity — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'System Integrity', 'url' => ''],
            ],
            'results' => $results,
        ]);
    }

    /** Read-only drill-down into one check's detail rows. */
    public function detail(): void
    {
        $code = (string)($_GET['code'] ?? '');
        $results = $this->service->runAll();
        $match = null;
        foreach ($results as $r) {
            if ($r['code'] === $code) { $match = $r; break; }
        }

        if (!$match) {
            Session::flash('error', 'Unknown diagnostic check.');
            $this->redirect(APP_URL . '/index.php?page=system-integrity');
            return;
        }

        $this->render('system-integrity/detail', [
            'pageTitle'   => $match['title'] . ' — System Integrity — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'System Integrity', 'url' => APP_URL . '/index.php?page=system-integrity'],
                ['label' => $match['title'], 'url' => ''],
            ],
            'result' => $match,
        ]);
    }
}
