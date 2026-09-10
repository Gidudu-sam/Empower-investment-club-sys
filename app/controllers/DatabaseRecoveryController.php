<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/services/DatabaseRecoveryService.php';
require_once APP_PATH  . '/models/SettingsModel.php';

/**
 * DatabaseRecoveryController — SA-6 (2026-09).
 *
 * Thin controller. This is deliberately NOT "Restore Production Database"
 * as an ordinary menu action (SA-6 brief Section 20) -- every action here
 * operates against the fixed, isolated SA-6 test database only, never
 * production. verify() is the only mutating action (POST+CSRF); it never
 * accepts a raw backup path, table name, or database name from the
 * request -- only a backup id resolved through BackupInventoryService's
 * own registry.
 */
class DatabaseRecoveryController extends Controller
{
    private DatabaseRecoveryService $service;
    private SettingsModel $settings;

    public function __construct()
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'system_admin'])) {
            Session::flash('error', 'Access denied. System Administrator privileges required.');
            $this->redirect(APP_URL . '/index.php?page=dashboard');
            exit;
        }
        $this->service  = new DatabaseRecoveryService();
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
        $this->render('recovery/index', [
            'pageTitle'   => 'Database Recovery — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Database Recovery', 'url' => ''],
            ],
            'backups' => $this->service->inventory(),
            'recent' => $this->service->recentRecoveries(50),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    /** The only mutating action: runs an isolated restore + verification for one backup. */
    public function verify(): void
    {
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=recovery');
            return;
        }
        $backupId = (string)($_POST['backup_id'] ?? '');
        if ($backupId === '' || !preg_match('/^[a-f0-9]{16}$/', $backupId)) {
            Session::flash('error', 'Invalid backup reference.');
            $this->redirect(APP_URL . '/index.php?page=recovery');
            return;
        }

        try {
            $restore = $this->service->restoreBackup($backupId, (int)Session::get('user_id'));
            $this->settings->log((int)Session::get('user_id'), 'recovery_restore_tested', "Isolated restore test for backup id {$backupId}: {$restore['status']}");

            if ($restore['status'] === 'restore_tested') {
                $this->service->verifyRestoredDatabase($restore['recovery_id']);
                $this->settings->log((int)Session::get('user_id'), 'recovery_verified', "Verified isolated restore #{$restore['recovery_id']}");
            }

            Session::flash('success', 'Recovery verification complete.');
            $this->redirect(APP_URL . '/index.php?page=recovery-result&id=' . $restore['recovery_id']);
        } catch (Throwable $e) {
            Session::flash('error', 'Recovery verification failed: ' . $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=recovery');
        }
    }

    public function result(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $recovery = $this->service->findRecovery($id);
        if (!$recovery) {
            Session::flash('error', 'Recovery record not found.');
            $this->redirect(APP_URL . '/index.php?page=recovery');
            return;
        }
        $summary = $recovery['verification_summary_json'] ? json_decode($recovery['verification_summary_json'], true) : null;

        $this->render('recovery/result', [
            'pageTitle'   => "Recovery #{$recovery['id']} — " . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Database Recovery', 'url' => APP_URL . '/index.php?page=recovery'],
                ['label' => "#{$recovery['id']}", 'url' => ''],
            ],
            'recovery' => $recovery, 'summary' => $summary,
        ]);
    }
}
