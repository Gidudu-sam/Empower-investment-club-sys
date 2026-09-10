<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/SettingsModel.php';
require_once APP_PATH  . '/models/UserModel.php';
require_once APP_PATH  . '/models/MemberModel.php';

/**
 * SettingsController — System Administration Module
 */
class SettingsController extends Controller
{
    private SettingsModel $settings;
    private UserModel     $userModel;
    private MemberModel   $memberModel;

    public function __construct()
    {
        $this->settings    = new SettingsModel();
        $this->userModel   = new UserModel();
        $this->memberModel = new MemberModel();
    }

    /**
     * SA-1 (System Administrator role refinement, 2026-09): System
     * Administrator has full CONFIGURATION authority over every settings
     * area in this controller (organization info, system config, receipt
     * config, financial-year/loan/withdrawal POLICY settings) -- this is
     * distinct from ordinary financial TRANSACTION authority, which stays
     * exactly where it already was (SavingsAccountController, LoanController,
     * RepaymentController, etc. were not touched by this stage). Kept as
     * its own named method, separate from requireSystemAdminOrAdmin()
     * below, even though both currently allow the same two roles -- they
     * govern conceptually different areas (general settings/policy
     * configuration vs. user-management/backup/audit) and may need to
     * diverge later without re-splitting a merged gate.
     */
    private function requireSettingsAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'system_admin'])) {
            Session::flash('error', 'Access denied. Administrator or System Administrator privileges required.');
            $this->redirect(APP_URL . '/index.php?page=dashboard');
            exit;
        }
    }

    private function requireSystemAdminOrAdmin(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'system_admin'])) {
            Session::flash('error', 'Access denied. Administrator or System Administrator privileges required.');
            $this->redirect(APP_URL . '/index.php?page=dashboard');
            exit;
        }
    }

    /**
     * User-management access: admin/system_admin only. Office Administrator
     * was briefly granted this too, then explicitly revoked -- kept as its
     * own named method (rather than merging back into
     * requireSystemAdminOrAdmin()) since the role_id validation and
     * richer audit messages added alongside it are independent
     * improvements worth keeping regardless of which roles pass the gate.
     */
    private function requireUserManagementAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'system_admin'])) {
            Session::flash('error', 'Access denied. Administrator or System Administrator privileges required.');
            $this->redirect(APP_URL . '/index.php?page=dashboard');
            exit;
        }
    }

    /** Chairman role-refinement (2026-09): read-only Audit Log visibility
     *  for Chairman -- "who approved this, who recorded it, who reversed
     *  it, when" is a governance-oversight need, distinct from Database/
     *  Backup access which stays admin/system_admin only on
     *  requireSystemAdminOrAdmin() above, untouched. Deliberately a new,
     *  separate gate rather than widening that one, since auditLogs() is
     *  the only one of its four actions this stage grants Chairman. */
    /** Stage 23: Vice Chairman added as deputy/alternate for Chairman's
     *  read-only oversight access. Secretary deliberately excluded --
     *  the system-wide audit log is a broader technical/governance tool
     *  matching Chairman/Vice Chairman/System Admin's oversight mandate,
     *  not Secretary's narrower, workflow-scoped authority; Secretary's
     *  own per-document approval history remains reachable via each
     *  workflow's own view page (investment/voucher/loan-application). */
    private function requireAuditLogAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'system_admin', 'chairman', 'vice_chairman'])) {
            Session::flash('error', 'Access denied. Administrator, System Administrator, Chairman, or Vice Chairman privileges required.');
            $this->redirect(APP_URL . '/index.php?page=dashboard');
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

    private function abort404(): never
    {
        http_response_code(404);
        require VIEW_PATH . '/errors/404.php';
        exit;
    }

    // ================================================================
    // GENERAL SETTINGS
    // ================================================================
    public function general(): void
    {
        $this->requireSettingsAccess();

        $this->render('settings/general', [
            'pageTitle'   => 'General Settings — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'General', 'url' => ''],
            ],
            'settings'  => $this->settings->getAllSettings(),
            'success'   => Session::flash('success'),
            'error'     => Session::flash('error'),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    /**
     * SA-1: narrowed to Organization Information ONLY (club identity/
     * contact/logo). 'currency' moved to systemConfigSave() below and
     * 'receipt_footer' removed entirely from this action -- it was being
     * saved here AND on the dedicated Receipt Settings page
     * (receiptSettingsSave()) at the same time, a genuine duplicate-
     * source-of-truth bug the SA-1 audit flagged. Receipt Settings remains
     * the one place that field is edited; no data was moved or migrated,
     * this only stops writing it from a second place.
     */
    public function generalSave(): void
    {
        $this->requireSettingsAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=settings-general');
            return;
        }

        $keys = ['club_name','club_motto','club_address','club_phone','club_email'];
        foreach ($keys as $k) {
            if (isset($_POST[$k])) {
                $this->settings->set($k, $this->sanitize($_POST[$k]));
            }
        }

        // Handle logo upload
        if (!empty($_FILES['club_logo']['name']) && $_FILES['club_logo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (in_array($_FILES['club_logo']['type'], $allowed)) {
                $ext  = pathinfo($_FILES['club_logo']['name'], PATHINFO_EXTENSION);
                $name = 'logo_' . time() . '.' . $ext;
                $dest = PUBLIC_PATH . '/uploads/' . $name;
                if (!is_dir(PUBLIC_PATH . '/uploads')) {
                    mkdir(PUBLIC_PATH . '/uploads', 0755, true);
                }
                if (move_uploaded_file($_FILES['club_logo']['tmp_name'], $dest)) {
                    $this->settings->set('club_logo', 'uploads/' . $name);
                }
            }
        }

        $this->settings->log((int)Session::get('user_id'), 'settings_updated', 'Updated organization information');
        Session::flash('success', 'Organization information saved successfully.');
        $this->redirect(APP_URL . '/index.php?page=settings-general');
    }

    /** SA-1: split out of generalSave() -- pure system/display configuration
     *  (currently just currency), distinct from organization identity. */
    public function systemConfigSave(): void
    {
        $this->requireSettingsAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=settings-general');
            return;
        }

        if (isset($_POST['currency'])) {
            $this->settings->set('currency', $this->sanitize($_POST['currency']));
        }

        $this->settings->log((int)Session::get('user_id'), 'settings_updated', 'Updated system configuration');
        Session::flash('success', 'System configuration saved successfully.');
        $this->redirect(APP_URL . '/index.php?page=settings-general');
    }

    // ================================================================
    // FINANCIAL YEAR — Stage 2 (Financial Years Consolidation): retired as
    // an independent implementation. FinancialYearController/Model is now
    // the single canonical workflow; every action below is a thin
    // compatibility redirect/delegate for old bookmarks, kept per the
    // "old routes must not continue executing the weaker legacy
    // implementation" rule -- SettingsModel's original
    // getFinancialYearsList()/createFinancialYear()/updateFinancialYear()/
    // activateFinancialYear()/closeFinancialYear() are left in place,
    // unused, rather than deleted.
    // ================================================================
    public function financialYears(): void
    {
        $this->requireSettingsAccess();
        // Old destination retired -- the canonical list/detail page is the
        // single authoritative Financial Years destination now.
        $this->redirect(APP_URL . '/index.php?page=financial-years');
    }

    public function financialYearSave(): void
    {
        $this->requireSettingsAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=financial-years');
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        // Delegate onto the canonical, validated model methods instead of
        // SettingsModel's unvalidated raw-SQL equivalents.
        try {
            if ($id > 0) {
                (new FinancialYearModel())->updateYear(
                    $id, $_POST['name'] ?? '', $_POST['start_date'] ?? '', $_POST['end_date'] ?? '',
                    (int)Session::get('user_id')
                );
                Session::flash('success', 'Financial year updated.');
            } else {
                $id = (new FinancialYearModel())->createYear(
                    $_POST['name'] ?? '', $_POST['start_date'] ?? '', $_POST['end_date'] ?? '',
                    (int)Session::get('user_id')
                );
                Session::flash('success', 'Financial year created.');
            }
            $this->redirect(APP_URL . '/index.php?page=financial-year-view&id=' . $id);
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=financial-years');
        }
    }

    public function financialYearActivate(): void
    {
        $this->requireSettingsAccess();
        $id = (int)($_GET['id'] ?? 0);
        // Delegate onto the canonical activation confirm page rather than
        // executing the old unguarded, unaudited, single-active-year-only
        // legacy activation directly -- an old bookmark now lands on the
        // same safe, reasoned workflow admin/treasurer use everywhere else.
        $this->redirect(APP_URL . '/index.php?page=financial-year-activate-confirm&id=' . $id);
    }

    public function financialYearClose(): void
    {
        $this->requireSettingsAccess();
        $id = (int)($_GET['id'] ?? 0);
        // The old action had no reason field and no period/balance checks
        // at all -- cannot safely auto-execute a close from here. Redirect
        // into the canonical guided close-confirm workflow instead, which
        // collects the mandatory reason and enforces every Stage-20 safeguard.
        $this->redirect(APP_URL . '/index.php?page=financial-year-close-confirm&id=' . $id);
    }

    // ================================================================
    // LOAN SETTINGS
    // ================================================================
    public function loanSettings(): void
    {
        $this->requireSettingsAccess();

        $this->render('settings/loans', [
            'pageTitle'   => 'Loan Settings — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Loan Settings', 'url' => ''],
            ],
            'settings'  => $this->settings->getAllSettings(),
            'success'   => Session::flash('success'),
            'error'     => Session::flash('error'),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function loanSettingsSave(): void
    {
        $this->requireSettingsAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=settings-loans');
            return;
        }

        $keys = ['loan_threshold','loan_rate_below','loan_rate_above'];
        foreach ($keys as $k) {
            if (isset($_POST[$k])) {
                $this->settings->set($k, $this->sanitize($_POST[$k]));
            }
        }

        $this->settings->log((int)Session::get('user_id'), 'settings_updated', 'Updated loan settings');
        Session::flash('success', 'Loan settings saved successfully.');
        $this->redirect(APP_URL . '/index.php?page=settings-loans');
    }

    // ================================================================
    // WITHDRAWAL SETTINGS
    // ================================================================
    public function withdrawalSettings(): void
    {
        $this->requireSettingsAccess();

        $this->render('settings/withdrawals', [
            'pageTitle'   => 'Withdrawal Settings — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Withdrawal Settings', 'url' => ''],
            ],
            'settings'  => $this->settings->getAllSettings(),
            'success'   => Session::flash('success'),
            'error'     => Session::flash('error'),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function withdrawalSettingsSave(): void
    {
        $this->requireSettingsAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=settings-withdrawals');
            return;
        }

        $keys = ['withdrawal_pct','retained_pct','max_withdrawals_year','share_value','min_required_shares'];
        foreach ($keys as $k) {
            if (isset($_POST[$k])) {
                $this->settings->set($k, $this->sanitize($_POST[$k]));
            }
        }

        $this->settings->log((int)Session::get('user_id'), 'settings_updated', 'Updated withdrawal/share settings');
        Session::flash('success', 'Withdrawal & share settings saved successfully.');
        $this->redirect(APP_URL . '/index.php?page=settings-withdrawals');
    }

    // ================================================================
    // RECEIPT SETTINGS
    // ================================================================
    public function receiptSettings(): void
    {
        $this->requireSettingsAccess();

        $this->render('settings/receipts', [
            'pageTitle'   => 'Receipt Settings — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Receipt Settings', 'url' => ''],
            ],
            'settings'  => $this->settings->getAllSettings(),
            'success'   => Session::flash('success'),
            'error'     => Session::flash('error'),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function receiptSettingsSave(): void
    {
        $this->requireSettingsAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=settings-receipts');
            return;
        }

        $keys = ['receipt_footer','receipt_authorized_name','receipt_prefix'];
        foreach ($keys as $k) {
            if (isset($_POST[$k])) {
                $this->settings->set($k, $this->sanitize($_POST[$k]));
            }
        }

        $this->settings->log((int)Session::get('user_id'), 'settings_updated', 'Updated receipt settings');
        Session::flash('success', 'Receipt settings saved successfully.');
        $this->redirect(APP_URL . '/index.php?page=settings-receipts');
    }

    // ================================================================
    // USER MANAGEMENT
    // ================================================================
    public function users(): void
    {
        $this->requireUserManagementAccess();

        $users = $this->settings->getAllUsers();
        $roles = $this->settings->getAllRoles();

        $this->render('settings/users', [
            'pageTitle'   => 'User Management — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Users', 'url' => ''],
            ],
            'users'         => $users,
            'roles'         => $roles,
            'currentUserId' => (int)Session::get('user_id'),
            'success'       => Session::flash('success'),
            'error'         => Session::flash('error'),
            'csrfToken'     => $this->getCsrf(),
        ]);
    }

    public function userSave(): void
    {
        $this->requireUserManagementAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=settings-users');
            return;
        }

        $id       = (int)($_POST['id'] ?? 0);
        $fullName = $this->sanitize($_POST['full_name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $phone    = $this->sanitize($_POST['phone'] ?? '');
        $roleId   = (int)($_POST['role_id'] ?? 3);
        $password = $_POST['password'] ?? '';

        if (empty($fullName) || empty($email)) {
            Session::flash('error', 'Name and email are required.');
            $this->redirect(APP_URL . '/index.php?page=settings-users');
            return;
        }

        // Never trust a client-submitted role_id -- verify it actually
        // exists in the roles table before it reaches the database layer.
        // Without this, an invalid id relied on the FK constraint alone,
        // which fails silently: createUser()/updateUser() swallow the
        // PDOException and return false, but their callers below never
        // checked that return value, so a rejected save still reported
        // "created/updated successfully" to the operator.
        $roleLookup = [];
        $roleNameById = [];
        foreach ($this->settings->getAllRoles() as $r) {
            $roleLookup[(int)$r['id']]   = $r['label'];
            $roleNameById[(int)$r['id']] = $r['name'];
        }
        if (!array_key_exists($roleId, $roleLookup)) {
            Session::flash('error', 'Invalid role selected.');
            $this->redirect(APP_URL . '/index.php?page=settings-users');
            return;
        }

        // Stage 14-B: member-account linking. A "member" account type is
        // exactly role_id = the 'member' role, carrying a real members.id;
        // every other role must never carry one. member_id is validated
        // here (the DB's own UNIQUE KEY on member_id is the final,
        // race-condition-proof guard -- this is the friendlier first
        // check, per the brief's explicit requirement not to rely on the
        // constraint alone).
        $isMemberRole = ($roleNameById[$roleId] ?? null) === 'member';
        $memberId = null;
        if ($isMemberRole) {
            $memberId = (int)($_POST['member_id'] ?? 0);
            if ($memberId < 1) {
                Session::flash('error', 'Select an existing member to link this account to.');
                $this->redirect(APP_URL . '/index.php?page=settings-users');
                return;
            }
            $member = $this->memberModel->find($memberId);
            if (!$member) {
                Session::flash('error', 'The selected member record could not be found.');
                $this->redirect(APP_URL . '/index.php?page=settings-users');
                return;
            }
            $existingLink = $this->userModel->findByMemberId($memberId);
            if ($existingLink && (int)$existingLink['id'] !== $id) {
                Session::flash('error', "This member already has a portal account ({$existingLink['email']}). Unlink or edit that account instead of creating another.");
                $this->redirect(APP_URL . '/index.php?page=settings-users');
                return;
            }
        }

        if ($id > 0) {
            // Self-role-escalation guard (Stage 6 security fix): a user may
            // edit their own name/email/phone/password through this form,
            // but may never change their own role_id -- regardless of
            // whether the change would be an escalation, a lateral move, or
            // even a demotion, since ranking privilege levels would need a
            // hierarchy this system doesn't otherwise have. Mirrors the
            // existing self-deactivation guard already in userToggle()
            // below (`$id !== (int)Session::get('user_id')`), applied here
            // to the role field specifically rather than the whole action.
            $currentUser = $this->userModel->find($id);
            if (!$currentUser) {
                Session::flash('error', 'User not found.');
                $this->redirect(APP_URL . '/index.php?page=settings-users');
                return;
            }
            $isSelf = $id === (int)Session::get('user_id');
            if ($isSelf && $roleId !== (int)$currentUser['role_id']) {
                $this->settings->log((int)Session::get('user_id'), 'self_role_change_blocked',
                    "Blocked attempt to change own role (from role_id {$currentUser['role_id']} to {$roleId})");
                Session::flash('error', 'You cannot change your own role. Ask another administrator or system administrator to do it for you.');
                $this->redirect(APP_URL . '/index.php?page=settings-users');
                return;
            }
            // Stage 14-B: relinking a member account's underlying member is
            // the same kind of identity change self-role-change already
            // guards against -- an account holder must never be able to
            // repoint their own portal identity to a different member.
            $existingMemberId = $currentUser['member_id'] !== null ? (int)$currentUser['member_id'] : null;
            if ($isSelf && $isMemberRole && $memberId !== $existingMemberId) {
                $this->settings->log((int)Session::get('user_id'), 'self_member_relink_blocked',
                    "Blocked attempt to change own linked member (from " . ($existingMemberId ?? 'none') . " to {$memberId})");
                Session::flash('error', 'You cannot relink your own account to a different member. Ask another administrator or system administrator to do it for you.');
                $this->redirect(APP_URL . '/index.php?page=settings-users');
                return;
            }

            // Update existing user. A role that isn't 'member' must never
            // carry a member_id -- clears any stale mapping if the role is
            // changed away from member.
            $data = [
                'full_name' => $fullName,
                'email'     => $email,
                'phone'     => $phone,
                'role_id'   => $roleId,
                'member_id' => $isMemberRole ? $memberId : null,
            ];
            if (!empty($password)) {
                $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            }
            $updated = $this->settings->updateUser($id, $data);
            if (!$updated) {
                Session::flash('error', "Could not update user '{$fullName}'. The selected member may already have a linked account.");
                $this->redirect(APP_URL . '/index.php?page=settings-users');
                return;
            }

            // Role changes get their own clearly-labeled audit entry (on
            // top of the generic "user_updated" one below), so a role
            // change is unambiguous in the audit trail rather than buried
            // in a generic "Updated user" line.
            if ((int)$currentUser['role_id'] !== $roleId) {
                $oldLabel = $roleLookup[(int)$currentUser['role_id']] ?? ('role #' . $currentUser['role_id']);
                $newLabel = $roleLookup[$roleId] ?? ('role #' . $roleId);
                $this->settings->log((int)Session::get('user_id'), 'user_role_changed',
                    "Changed role for {$fullName} from \"{$oldLabel}\" to \"{$newLabel}\"");
            }
            // Member-link change gets its own audit entry too, independent
            // of whether the role itself changed (a member account can be
            // relinked to a different member without a role change).
            if ($existingMemberId !== ($isMemberRole ? $memberId : null)) {
                $newMemberLabel = $isMemberRole ? (($member['member_number'] ?? '') . ' — ' . trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))) : 'none';
                $this->settings->log((int)Session::get('user_id'), 'user_member_link_changed',
                    "Changed member link for {$fullName} from member_id " . ($existingMemberId ?? 'none') . " to " . ($isMemberRole ? "{$memberId} ({$newMemberLabel})" : 'none'));
            }
            $this->settings->log((int)Session::get('user_id'), 'user_updated', "Updated user: {$fullName}");
            Session::flash('success', "User '{$fullName}' updated successfully.");
        } else {
            // Create new user
            if (empty($password)) {
                Session::flash('error', 'Password is required for new users.');
                $this->redirect(APP_URL . '/index.php?page=settings-users');
                return;
            }
            $data = [
                'full_name'             => $fullName,
                'email'                 => $email,
                'phone'                 => $phone,
                'role_id'               => $roleId,
                'member_id'             => $isMemberRole ? $memberId : null,
                // Stage 14-B: a newly-provisioned member account must
                // change its administrator-set password before it can use
                // the portal for anything else (enforced in
                // Session::requireMember()). Staff accounts keep today's
                // existing behavior -- no forced change.
                'force_password_change' => $isMemberRole ? 1 : 0,
                'password_hash'         => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                'is_active'     => 1,
            ];
            $newId = $this->settings->createUser($data);
            if (!$newId) {
                Session::flash('error', "Could not create user '{$fullName}'. The email may already be in use" . ($isMemberRole ? ', or the selected member already has a linked account' : '') . '.');
                $this->redirect(APP_URL . '/index.php?page=settings-users');
                return;
            }
            $this->settings->log((int)Session::get('user_id'), 'user_created',
                "Created user: {$fullName} (role: " . ($roleLookup[$roleId] ?? "#{$roleId}") . ")");
            // Stage 14-B: a distinct, specifically-named audit entry for
            // member-account provisioning (on top of the generic
            // "user_created" one above), carrying the member identifiers
            // an administrator/auditor would actually want to search by.
            // No credential of any kind is included.
            if ($isMemberRole) {
                $this->settings->log((int)Session::get('user_id'), 'MEMBER_ACCOUNT_CREATED',
                    "Created member portal account for {$fullName} — linked to member "
                    . ($member['member_number'] ?? '') . " (member_id={$memberId}, created_user_id={$newId})");
            }
            Session::flash('success', "User '{$fullName}' created successfully.");
        }

        $this->redirect(APP_URL . '/index.php?page=settings-users');
    }

    /**
     * Stage 14-B: AJAX member search for the "create/edit member account"
     * form -- lets the administrator search by member number, name, or
     * phone and select an existing member rather than typing a raw
     * members.id. Gated identically to the rest of user management
     * (requireUserManagementAccess(), already called in the constructor's
     * absence here -- this action calls it explicitly like every other
     * action in this controller). Returns only the fields the form needs
     * to display a result and confirm the selection, plus whether that
     * member already has a linked account (so the picker can show it
     * before the administrator even submits the form).
     */
    public function userMemberSearch(): void
    {
        $this->requireUserManagementAccess();

        $term = trim($_GET['q'] ?? '');
        if (strlen($term) < 2) {
            $this->json(['members' => []]);
            return;
        }

        $result = $this->memberModel->search($term, '', '', '', 1, 10);
        $out = array_map(function ($m) {
            $linked = $this->userModel->findByMemberId((int)$m['id']);
            return [
                'id'             => (int)$m['id'],
                'member_number'  => $m['member_number'],
                'full_name'      => $m['first_name'] . ' ' . $m['last_name'],
                'phone'          => $m['phone'],
                'status'         => $m['status'],
                'already_linked' => $linked !== false,
            ];
        }, $result['rows']);

        $this->json(['members' => $out]);
    }

    public function userToggle(): void
    {
        $this->requireUserManagementAccess();
        // Stage 13-C (H-1): was GET-triggered with no CSRF check -- a
        // crafted link/redirect while an admin was logged in could
        // deactivate any other system user with no confirmation.
        if (!$this->isPost()) {
            Session::flash('error', 'Invalid request method.');
            $this->redirect(APP_URL . '/index.php?page=settings-users');
            return;
        }
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=settings-users');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && $id !== (int)Session::get('user_id')) {
            $target = $this->userModel->find($id);
            $wasActive = $target ? (int)$target['is_active'] === 1 : null;
            $this->settings->toggleUser($id);
            $label = $target['full_name'] ?? "user ID {$id}";
            $verb = $wasActive === null ? 'Toggled' : ($wasActive ? 'Deactivated' : 'Activated');
            $this->settings->log((int)Session::get('user_id'), 'user_toggled', "{$verb} {$label}");
            Session::flash('success', 'User status updated.');
        }
        $this->redirect(APP_URL . '/index.php?page=settings-users');
    }

    public function userResetPassword(): void
    {
        $this->requireUserManagementAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch.');
            $this->redirect(APP_URL . '/index.php?page=settings-users');
            return;
        }

        $id       = (int)($_POST['user_id'] ?? 0);
        $password = $_POST['new_password'] ?? '';

        if ($id > 0 && !empty($password)) {
            $target = $this->userModel->find($id);
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            // Never log the password itself, only that a reset happened and for whom.
            $this->settings->updateUser($id, ['password_hash' => $hash]);
            $this->settings->log((int)Session::get('user_id'), 'password_reset',
                'Reset password for ' . ($target['full_name'] ?? "user ID {$id}"));
            Session::flash('success', 'Password reset successfully.');
        }
        $this->redirect(APP_URL . '/index.php?page=settings-users');
    }

    // ================================================================
    // DATABASE MANAGEMENT
    // ================================================================
    public function database(): void
    {
        $this->requireSystemAdminOrAdmin();

        $backups = $this->settings->getBackupHistory();

        $this->render('settings/database', [
            'pageTitle'   => 'Database Management — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Database', 'url' => ''],
            ],
            'backups'   => $backups,
            'success'   => Session::flash('success'),
            'error'     => Session::flash('error'),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function databaseBackup(): void
    {
        $this->requireSystemAdminOrAdmin();

        // AUTH-D-01 (Stage 13-D/13-E): the view already renders a proper
        // POST form with a csrf_token field (app/views/settings/database.php)
        // -- this method simply never checked it, so the token was
        // generated and rendered but silently ignored, leaving the action
        // reachable via a plain GET (e.g. a crafted link visited by a
        // logged-in admin) and forgeable via CSRF regardless of method.
        if (!$this->isPost() || !$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=settings-database');
            return;
        }

        $backupDir = ROOT_PATH . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $filename = 'backup_' . date('Y-m-d_His') . '.sql';
        $filepath = $backupDir . '/' . $filename;

        // Generate backup using mysqldump or PHP-based export
        $tables = $this->settings->getAllTables();
        // Bug fix (2026-09): tables were previously restored in
        // SHOW TABLES order (alphabetical) with no FK-check suspension,
        // so a table whose FK target sorts later alphabetically (e.g.
        // accounting_periods -> financial_years) failed to import at
        // all -- confirmed by actually restoring a generated backup into
        // a throwaway database. Every real mysqldump-produced backup in
        // this project's own backups/ directory already wraps its
        // CREATE TABLE section in this exact same FOREIGN_KEY_CHECKS
        // toggle; this PHP-generated backup never did.
        $sql    = "-- Empower DB Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n-- ----------------------------------------\n\n"
                . "SET FOREIGN_KEY_CHECKS=0;\n\n";
        $skipped = [];

        $db = Database::getInstance()->getConnection();
        foreach ($tables as $table) {
            // Bug fix (2026-09): a handful of legacy tables are
            // #mysql50#-encoded storage-engine artifacts (pre-dating this
            // engagement, already documented in the SA-6 recovery audit)
            // that SHOW CREATE TABLE/SELECT cannot read -- this crashed
            // every backup attempt with an uncaught PDOException. Skip
            // any table that can't actually be read rather than
            // aborting the whole backup; every healthy table is
            // completely unaffected.
            if (str_contains($table, '#')) {
                $skipped[] = $table;
                continue;
            }
            try {
                // Bug fix (2026-09): SHOW TABLES also lists VIEWs (this
                // schema has a genuine v_savings_ledger view alongside
                // its tables), and SHOW CREATE TABLE on a view returns a
                // "Create View" column, not "Create Table" -- silently
                // producing a malformed/empty CREATE statement (an
                // undefined-array-key warning, then a broken dump entry
                // that would fail to restore). Views need SHOW CREATE
                // VIEW and are recreated with CREATE OR REPLACE VIEW
                // (dropping a view a real table depends on is never
                // correct); only base tables get DROP TABLE + data rows.
                $isView = (bool)$db->query("SELECT 1 FROM information_schema.views WHERE table_schema = DATABASE() AND table_name = " . $db->quote($table))->fetchColumn();

                if ($isView) {
                    $createStmt = $db->query("SHOW CREATE VIEW `{$table}`")->fetch();
                    $sql .= $createStmt['Create View'] . ";\n\n";
                    continue;
                }

                // CREATE TABLE
                $createStmt = $db->query("SHOW CREATE TABLE `{$table}`")->fetch();
                $tableSql = "DROP TABLE IF EXISTS `{$table}`;\n" . $createStmt['Create Table'] . ";\n\n";

                // INSERT DATA
                $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        $values = array_map(function ($v) use ($db) {
                            return $v === null ? 'NULL' : $db->quote($v);
                        }, $row);
                        $tableSql .= "INSERT INTO `{$table}` VALUES(" . implode(',', $values) . ");\n";
                    }
                    $tableSql .= "\n";
                }
                $sql .= $tableSql;
            } catch (PDOException $e) {
                $skipped[] = $table;
            }
        }
        if (!empty($skipped)) {
            $sql .= "-- NOTE: " . count($skipped) . " unreadable legacy table(s) skipped: " . implode(', ', $skipped) . "\n";
        }
        $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";

        file_put_contents($filepath, $sql);
        $fileSize = filesize($filepath);

        // Record in database
        $this->settings->recordBackup($filename, $fileSize, (int)Session::get('user_id'));
        $this->settings->log((int)Session::get('user_id'), 'database_backup', "Created backup: {$filename}" . (!empty($skipped) ? ' (' . count($skipped) . ' unreadable legacy table(s) skipped)' : ''));
        $successMsg = "Database backup created: {$filename}";
        if (!empty($skipped)) {
            $successMsg .= ' (' . count($skipped) . ' unreadable legacy table(s) skipped -- pre-existing storage-engine artifacts, not part of the live application)';
        }
        Session::flash('success', $successMsg);
        $this->redirect(APP_URL . '/index.php?page=settings-database');
    }

    public function databaseDownload(): void
    {
        $this->requireSystemAdminOrAdmin();
        $filename = basename($_GET['file'] ?? '');
        $filepath = ROOT_PATH . '/backups/' . $filename;

        if (empty($filename) || !file_exists($filepath)) {
            Session::flash('error', 'Backup file not found.');
            $this->redirect(APP_URL . '/index.php?page=settings-database');
            return;
        }

        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }

    // ================================================================
    // AUDIT LOGS
    // ================================================================
    public function auditLogs(): void
    {
        $this->requireAuditLogAccess();

        $search = trim($_GET['search'] ?? '');
        $action = trim($_GET['action'] ?? '');
        $page   = max(1, (int)($_GET['p'] ?? 1));

        $result = $this->settings->getAuditLogs($search, $action, $page, 25);

        $this->render('settings/audit-logs', [
            'pageTitle'   => 'Audit Logs — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Settings', 'url' => APP_URL . '/index.php?page=settings-general'],
                ['label' => 'Audit Logs', 'url' => ''],
            ],
            'logs'        => $result['rows'],
            'total'       => $result['total'],
            'pages'       => $result['pages'],
            'currentPage' => $page,
            'search'      => $search,
            'action'      => $action,
        ]);
    }
}
