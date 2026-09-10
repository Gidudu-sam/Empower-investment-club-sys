<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/models/UserModel.php';
require_once APP_PATH  . '/models/SettingsModel.php';

/**
 * MemberAccountImportController — Stage 14-B Phase 6, bulk member portal
 * account provisioning. Deliberately follows MemberImportController's
 * exact upload -> parse -> validate -> preview -> confirm -> execute
 * shape (same routing style, same session-based result-report pattern)
 * rather than inventing a second import architecture.
 *
 * The template/input column is `member_number` (the human/business
 * identifier), never `member_id` -- the system resolves member_number ->
 * members.id -> users.member_id itself; an administrator never types a
 * raw internal id.
 */
class MemberAccountImportController extends Controller
{
    private MemberModel   $memberModel;
    private UserModel     $userModel;
    private SettingsModel $settings;
    private int $memberRoleId = 0;

    public function __construct()
    {
        $this->memberModel = new MemberModel();
        $this->userModel   = new UserModel();
        $this->settings    = new SettingsModel();
        foreach ($this->settings->getAllRoles() as $r) {
            if ($r['name'] === 'member') { $this->memberRoleId = (int)$r['id']; break; }
        }
    }

    private function requireAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'system_admin'])) {
            Session::flash('error', 'Access denied. Administrator or System Administrator privileges required.');
            $this->redirect(APP_URL . '/index.php?page=settings-users');
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
    // UPLOAD PAGE
    // ================================================================
    public function index(): void
    {
        $this->requireAccess();
        $this->render('member-accounts/import', [
            'pageTitle'   => 'Bulk Member Account Provisioning — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Users', 'url' => APP_URL . '/index.php?page=settings-users'],
                ['label' => 'Bulk Member Accounts'],
            ],
            'csrfToken' => $this->getCsrf(),
            'success'   => Session::flash('success'),
            'error'     => Session::flash('error'),
        ]);
    }

    // ================================================================
    // TEMPLATE DOWNLOAD
    // ================================================================
    public function template(): void
    {
        $this->requireAccess();
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="member_account_import_template.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['member_number']);
        fputcsv($out, ['EMP0001']);
        fclose($out);
        exit;
    }

    // ================================================================
    // PREVIEW (AJAX) — no account is created here. Every row is
    // classified as exactly one of: valid / already_has_account /
    // member_not_found / duplicate_row / invalid_format.
    // ================================================================
    public function preview(): void
    {
        $this->requireAccess();

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['error' => 'No file uploaded.'], 400);
            return;
        }
        $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $this->json(['error' => 'Only .csv files are supported.'], 400);
            return;
        }

        $rows = $this->parseCsv($_FILES['import_file']['tmp_name']);
        if (empty($rows)) {
            $this->json(['error' => 'No data found in file.'], 400);
            return;
        }

        $seenNumbers = [];
        $validated = [];
        $counts = ['valid' => 0, 'already_has_account' => 0, 'member_not_found' => 0, 'duplicate_row' => 0, 'invalid_format' => 0];

        foreach ($rows as $row) {
            $memberNumber = trim($row['member_number'] ?? '');
            $entry = ['row_num' => $row['row_num'], 'member_number' => $memberNumber];

            if ($memberNumber === '') {
                $entry['status'] = 'invalid_format';
                $entry['message'] = 'member_number is blank.';
                $counts['invalid_format']++;
                $validated[] = $entry;
                continue;
            }
            if (isset($seenNumbers[$memberNumber])) {
                $entry['status'] = 'duplicate_row';
                $entry['message'] = "Duplicate of row {$seenNumbers[$memberNumber]} in this same file.";
                $counts['duplicate_row']++;
                $validated[] = $entry;
                continue;
            }
            $seenNumbers[$memberNumber] = $row['row_num'];

            $member = $this->memberModel->findByMemberNumber($memberNumber);
            if (!$member) {
                $entry['status'] = 'member_not_found';
                $entry['message'] = "No member with number \"{$memberNumber}\" exists.";
                $counts['member_not_found']++;
                $validated[] = $entry;
                continue;
            }
            $entry['member_id'] = (int)$member['id'];
            $entry['full_name'] = $member['first_name'] . ' ' . $member['last_name'];

            $existing = $this->userModel->findByMemberId((int)$member['id']);
            if ($existing) {
                $entry['status'] = 'already_has_account';
                $entry['message'] = "Already linked to account {$existing['email']}.";
                $counts['already_has_account']++;
                $validated[] = $entry;
                continue;
            }

            $entry['status'] = 'valid';
            $entry['message'] = 'Ready to create.';
            $counts['valid']++;
            $validated[] = $entry;
        }

        $this->json([
            'rows'   => $validated,
            'totals' => array_merge(['total' => count($rows)], $counts),
        ]);
    }

    // ================================================================
    // EXECUTE — single atomic transaction. Every account created here
    // gets role=member, force_password_change=1, and a freshly-generated
    // random password shown to the administrator exactly once in the
    // result report immediately after commit (never logged, never
    // stored anywhere but the bcrypt hash) -- there is no email/SMS
    // delivery mechanism anywhere in this application to hand the
    // credential to the member automatically, so this mirrors the
    // existing single-account flow's own model (an administrator sets/
    // reads a password and communicates it through their own trusted
    // channel), just batched.
    // ================================================================
    public function process(): void
    {
        $this->requireAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=member-account-import');
            return;
        }

        $rows = json_decode($_POST['import_data'] ?? '', true);
        if (empty($rows) || !is_array($rows)) {
            Session::flash('error', 'No valid data to import.');
            $this->redirect(APP_URL . '/index.php?page=member-account-import');
            return;
        }

        $db = Database::getInstance()->getConnection();
        $adminUserId = (int)Session::get('user_id');
        $created = 0; $skipped = 0; $errors = 0;
        $log = [];       // safe to display -- no credentials
        $credentials = []; // shown once, this response only, never persisted or logged

        try {
            $db->beginTransaction();

            foreach ($rows as $row) {
                if (($row['status'] ?? '') !== 'valid') {
                    $skipped++;
                    $log[] = ['row' => $row['row_num'] ?? 0, 'status' => 'skipped', 'message' => $row['message'] ?? 'Not ready'];
                    continue;
                }

                // Re-verify inside the transaction -- the preview above is
                // advisory (time may have passed, another admin may have
                // acted); the actual duplicate-account guard is the
                // database's own UNIQUE KEY on users.member_id, exercised
                // here as a real INSERT, not re-derived from stale preview
                // data. This is also what makes two concurrent bulk
                // imports targeting the same member safe -- one INSERT
                // wins, the other fails this check or the constraint.
                $memberId = (int)($row['member_id'] ?? 0);
                $member   = $memberId > 0 ? $this->memberModel->find($memberId) : false;
                if (!$member) {
                    $errors++;
                    $log[] = ['row' => $row['row_num'] ?? 0, 'status' => 'error', 'message' => 'Member no longer found.'];
                    continue;
                }
                if ($this->userModel->findByMemberId($memberId)) {
                    $skipped++;
                    $log[] = ['row' => $row['row_num'] ?? 0, 'status' => 'skipped', 'message' => 'Member already has an account (created since preview).'];
                    continue;
                }

                $tempPassword = bin2hex(random_bytes(6)); // 12 hex chars, cryptographically random
                $email = $this->generateAccountEmail($member);

                $data = [
                    'full_name'             => trim($member['first_name'] . ' ' . $member['last_name']),
                    'email'                 => $email,
                    'phone'                 => $member['phone'],
                    'role_id'               => $this->memberRoleId,
                    'member_id'             => $memberId,
                    'force_password_change' => 1,
                    'password_hash'         => password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 12]),
                    'is_active'             => 1,
                ];
                $newId = $this->settings->createUser($data);
                if (!$newId) {
                    $errors++;
                    $log[] = ['row' => $row['row_num'] ?? 0, 'status' => 'error', 'message' => "Could not create account for {$member['member_number']} (email may already be in use)."];
                    continue;
                }

                $created++;
                $log[] = ['row' => $row['row_num'] ?? 0, 'status' => 'created', 'message' => "Created account for {$member['member_number']} — {$data['full_name']}"];
                $credentials[] = ['member_number' => $member['member_number'], 'full_name' => $data['full_name'], 'email' => $email, 'temp_password' => $tempPassword];
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            Session::flash('error', 'Bulk import failed and was rolled back: ' . $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=member-account-import');
            return;
        }

        // Stage 14-B: one summary audit entry for the batch (counts only,
        // no credentials) plus the existing generic per-account
        // MEMBER_ACCOUNT_CREATED-style trail already written by
        // createUser() callers elsewhere would be redundant here, so this
        // bulk-specific action is the authoritative record for this batch.
        $this->settings->log($adminUserId, 'BULK_MEMBER_ACCOUNTS_CREATED',
            "Bulk member account import: {$created} created, {$skipped} skipped, {$errors} errors.");

        Session::set('member_account_import_result', [
            'total' => count($rows), 'created' => $created, 'skipped' => $skipped, 'errors' => $errors,
            'log' => $log, 'credentials' => $credentials,
        ]);
        Session::flash('success', "Bulk import completed: {$created} accounts created.");
        $this->redirect(APP_URL . '/index.php?page=member-account-import');
    }

    // ----------------------------------------------------------------
    private function generateAccountEmail(array $member): string
    {
        if (!empty($member['email'])) {
            $existing = $this->userModel->findByEmail($member['email']);
            if (!$existing) return strtolower(trim($member['email']));
        }
        // No usable/unique member email on file -- derive a stable,
        // unique placeholder from the member number rather than blocking
        // the whole batch on a missing email column.
        return strtolower($member['member_number']) . '@members.empower.local';
    }

    private function parseCsv(string $filepath): array
    {
        $rows = [];
        $handle = fopen($filepath, 'r');
        if (!$handle) return [];
        $headers = fgetcsv($handle);
        if (!$headers) { fclose($handle); return []; }
        $headers = array_map(fn($h) => strtolower(trim($h)), $headers);
        $rowNum = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count($data) < 1 || empty(array_filter($data))) continue;
            $row = [];
            foreach ($headers as $i => $h) { $row[$h] = trim($data[$i] ?? ''); }
            $row['row_num'] = $rowNum;
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }
}
