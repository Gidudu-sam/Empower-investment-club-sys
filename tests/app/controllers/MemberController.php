<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/models/FeeModel.php';

/**
 * MemberController
 * Routes: members, member-add, member-edit, member-view, member-delete, member-status-change
 */
class MemberController extends Controller
{
    private MemberModel $model;

    public function __construct()
    {
        $this->model = new MemberModel();
    }

    /** Role-policy correction: member registration was originally an
     *  explicit cashier capability (front-desk enrollment) with treasurer
     *  also carried along. The finalized six-role policy narrows this
     *  deliberately: Office Administrator is now the sole member-facing
     *  registration officer. Cashier is explicitly a money-processing role
     *  ("Cashier should NOT: Register members") and Treasurer is explicitly
     *  a financial-control role ("Cannot register/add new members") --
     *  both intentionally removed here, not an oversight. */
    private function requireAddAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'office_admin'])) {
            Session::flash('error', 'Access denied. You do not have permission to add a member.');
            $this->redirect(APP_URL . '/index.php?page=members');
            exit;
        }
    }

    /** Editing an existing member's details -- admin/office_admin only.
     *  Sidebar-redesign policy alignment: treasurer's prior edit grant is
     *  deliberately revoked here -- Treasurer is financial-control/oversight
     *  only and should view a member's profile, not modify it. Matches the
     *  now-explicit "Treasurer does not register or edit members" policy. */
    private function requireEditAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'office_admin'])) {
            Session::flash('error', 'Access denied. Only admin or office administrator can edit a member.');
            $this->redirect(APP_URL . '/index.php?page=members');
            exit;
        }
    }

    /** Stage 1 security remediation: delete/changeStatus — irreversible or status-changing, admin only. */
    private function requireAdmin(): void
    {
        Session::requireAuth();
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Access denied. Only administrators can perform this action.');
            $this->redirect(APP_URL . '/index.php?page=members');
            exit;
        }
    }

    // ----------------------------------------------------------------
    // LIST
    // ----------------------------------------------------------------
    public function index(): void
    {
        Session::requireAuth();

        $this->model->syncDormantStatus();

        $term     = trim($_GET['search']    ?? '');
        $status   = trim($_GET['status']    ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo   = trim($_GET['date_to']   ?? '');
        $page     = max(1, (int)($_GET['p'] ?? 1));

        $result = $this->model->search($term, $status, $dateFrom, $dateTo, $page, 15);

        $this->render('members/index', [
            'pageTitle'   => 'Members — ' . APP_NAME,
            'breadcrumbs' => [['label' => 'Members']],
            'members'     => $result['rows'],
            'total'       => $result['total'],
            'pages'       => $result['pages'],
            'currentPage' => $page,
            'search'      => $term,
            'status'      => $status,
            'dateFrom'    => $dateFrom,
            'dateTo'      => $dateTo,
            'countAll'     => $this->model->countAll(),
            'countActive'  => $this->model->countActive(),
            'countDormant' => $this->model->countDormant(),
            'countInactive'=> $this->model->countInactive(),
            'success'     => Session::flash('success'),
            'error'       => Session::flash('error'),
            'csrfToken'   => $this->getCsrf(),
        ], 'main');
    }

    /** Smart Import: MemberImportController::process() stores the post-
     *  commit report via Session::set() (plain, not flash-backed) --
     *  same convention the original member-import page always used. Read
     *  it the same way here so the report survives exactly one page load
     *  after a bulk import lands back on this page. */
    private function consumeImportResult(): ?array
    {
        $result = Session::get('member_import_result');
        if ($result) Session::remove('member_import_result');
        return $result;
    }

    // ----------------------------------------------------------------
    // ADD
    // ----------------------------------------------------------------
    public function add(): void
    {
        $this->requireAddAccess();

        if ($this->isPost()) {
            $this->handleSave();
            return;
        }

        $this->render('members/form', [
            'pageTitle'    => 'Add Member — ' . APP_NAME,
            'breadcrumbs'  => [
                ['label' => 'Members', 'url' => APP_URL . '/index.php?page=members'],
                ['label' => 'Add Member'],
            ],
            'formAction'        => APP_URL . '/index.php?page=member-add',
            'formMode'          => 'add',
            'member'            => Session::flash('form_old') ?? [],
            'errors'            => Session::flash('form_errors') ?? [],
            'memberNumber'      => $this->model->generateMemberNumber(),
            'nextAccountNumber' => $this->model->generateAccountNumber(),
            'csrfToken'         => $this->getCsrf(),
            // Smart Import: bulk-import success/error and the post-commit
            // report are flashed by MemberImportController::process() and
            // land here now that the import panel lives on this page --
            // previously only the (unlinked) member-import page ever read
            // these.
            'success'           => Session::flash('success'),
            'error'             => Session::flash('error'),
            'importResult'      => $this->consumeImportResult(),
        ], 'main');
    }

    // ----------------------------------------------------------------
    // EDIT
    // ----------------------------------------------------------------
    public function edit(): void
    {
        $this->requireEditAccess();

        $id     = (int)($_GET['id'] ?? 0);
        $member = $this->findOrAbort($id);

        if ($this->isPost()) {
            $this->handleSave($id, $member);
            return;
        }

        $old = Session::flash('form_old') ?? [];
        if ($old) $member = array_merge($member, $old);

        $this->render('members/form', [
            'pageTitle'    => 'Edit Member — ' . APP_NAME,
            'breadcrumbs'  => [
                ['label' => 'Members', 'url' => APP_URL . '/index.php?page=members'],
                ['label' => $member['first_name'] . ' ' . $member['last_name'],
                 'url'   => APP_URL . '/index.php?page=member-view&id=' . $id],
                ['label' => 'Edit'],
            ],
            'formAction'        => APP_URL . '/index.php?page=member-edit&id=' . $id,
            'formMode'          => 'edit',
            'member'            => $member,
            'errors'            => Session::flash('form_errors') ?? [],
            'memberNumber'      => $member['member_number'],
            'nextAccountNumber' => $this->model->generateAccountNumber(),
            'csrfToken'         => $this->getCsrf(),
        ], 'main');
    }

    // ----------------------------------------------------------------
    // VIEW
    // ----------------------------------------------------------------
    public function view(): void
    {
        Session::requireAuth();

        $id     = (int)($_GET['id'] ?? 0);
        $member = $this->findOrAbort($id);

        $this->render('members/view', [
            'pageTitle'   => $member['first_name'] . ' ' . $member['last_name'] . ' — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Members', 'url' => APP_URL . '/index.php?page=members'],
                ['label' => $member['first_name'] . ' ' . $member['last_name']],
            ],
            'member'  => $member,
            'success' => Session::flash('success'),
            'error'   => Session::flash('error'),
            'csrfToken' => $this->getCsrf(),
        ], 'main');
    }

    // ----------------------------------------------------------------
    // DELETE
    // ----------------------------------------------------------------
    public function delete(): void
    {
        $this->requireAdmin();

        // Stage 13-C (H-1): this action used to mutate on a bare GET with no
        // CSRF check -- a crafted link/redirect while an admin was logged in
        // could delete a member with no confirmation. Now POST + CSRF only.
        if (!$this->isPost()) {
            Session::flash('error', 'Invalid request method.');
            $this->redirect(APP_URL . '/index.php?page=members');
            return;
        }
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=members');
            return;
        }

        $id     = (int)($_POST['id'] ?? 0);
        $member = $this->findOrAbort($id);

        if ($this->model->delete($id)) {
            $this->model->log(
                (int)Session::get('user_id'),
                'member_deleted',
                "Deleted member {$member['member_number']} — {$member['first_name']} {$member['last_name']}"
            );
            Session::flash('success', "Member {$member['member_number']} deleted successfully.");
        } else {
            Session::flash('error', 'Could not delete the member record.');
        }

        $this->redirect(APP_URL . '/index.php?page=members');
    }

    // ----------------------------------------------------------------
    // CHANGE STATUS (Stage 6-A — replaces the old binary toggleStatus();
    // Stage 6-C — every successful call here establishes status_source =
    // 'manual', even a same-status re-submission, per that stage's
    // explicit business decision that using this dedicated action is
    // itself the deliberate act that grants manual authority)
    // ----------------------------------------------------------------
    public function changeStatus(): void
    {
        $this->requireAdmin();

        // Stage 13-C (H-1): same GET+no-CSRF gap as delete() above.
        if (!$this->isPost()) {
            Session::flash('error', 'Invalid request method.');
            $this->redirect(APP_URL . '/index.php?page=members');
            return;
        }
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=members');
            return;
        }

        $id        = (int)($_POST['id'] ?? 0);
        $member    = $this->findOrAbort($id);
        $newStatus = $_POST['status'] ?? '';

        // Stage 6-C: status_source is NEVER read from $_POST -- it is
        // always server-derived. The only signal that grants 'manual' is
        // this admin-only, CSRF-protected action actually being reached.
        if (!in_array($newStatus, ['active', 'inactive', 'dormant'], true)) {
            Session::flash('error', 'Invalid status selected.');
            $this->redirect(APP_URL . '/index.php?page=member-view&id=' . $id);
            return;
        }

        $oldStatus = $member['status'];

        if (!$this->model->changeStatus($id, $newStatus)) {
            Session::flash('error', 'Could not update status.');
            $this->redirect(APP_URL . '/index.php?page=member-view&id=' . $id);
            return;
        }

        if ($oldStatus === $newStatus) {
            // Stage 6-C: the status value itself is unchanged, but this
            // explicit re-submission still (re-)establishes manual
            // authority -- status_source is now 'manual' regardless of
            // what it was before. Logged distinctly from an actual
            // from->to change so the audit trail reads accurately.
            $this->model->log(
                (int)Session::get('user_id'),
                'member_status_changed',
                "Member {$member['member_number']} status reaffirmed as {$newStatus} (manual authority established)"
            );
            Session::flash('success', "Status confirmed as {$newStatus} (now manually controlled).");
            $this->redirect(APP_URL . '/index.php?page=member-view&id=' . $id);
            return;
        }

        $this->model->log(
            (int)Session::get('user_id'),
            'member_status_changed',
            "Member {$member['member_number']} status manually changed from {$oldStatus} to {$newStatus}"
        );

        Session::flash('success', "Status changed to " . ucfirst($newStatus) . ".");
        $this->redirect(APP_URL . '/index.php?page=member-view&id=' . $id);
    }

    // ----------------------------------------------------------------
    // SAVE HANDLER (shared add + edit)
    // ----------------------------------------------------------------
    private function handleSave(?int $id = null, array $existing = []): void
    {
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect($id
                ? APP_URL . '/index.php?page=member-edit&id=' . $id
                : APP_URL . '/index.php?page=member-add');
            return;
        }

        $input  = $this->collectInput($existing['status'] ?? 'active');
        $errors = $this->validate($input, $id);

        if ($errors) {
            Session::flash('form_errors', $errors);
            Session::flash('form_old',    $input);
            $this->redirect($id
                ? APP_URL . '/index.php?page=member-edit&id=' . $id
                : APP_URL . '/index.php?page=member-add');
            return;
        }

        $userId = (int)Session::get('user_id');

        if ($id === null) {
            $input['member_number'] = $this->model->generateMemberNumber();
            $input['created_by']    = $userId;
            // Stage 6-C: a brand-new member has no prior manual precedent
            // to preserve -- their status_source starts 'automatic'
            // (matches the column's own DB-level default; set explicitly
            // here rather than relying on that default implicitly, so the
            // intent is visible in source). Even if the registering
            // admin/office_admin picked a non-default status on this
            // form, that is treated as this member's starting point, not
            // a "change" -- syncDormantStatus() is free to manage them
            // from here on, exactly like every other automatic member.
            $input['status_source'] = 'automatic';

            try {
                $result = $this->model->createWithCompulsoryAccount($input, $userId);
                $newId = $result['member_id'];

                $this->model->log($userId, 'member_added',
                    "Added member {$input['member_number']} — {$input['first_name']} {$input['last_name']}");

                // Auto-charge registration fee (unchanged: outside the member+
                // account transaction, same as before this change — see the
                // comment on MemberModel::createWithCompulsoryAccount()).
                $feeModel = new FeeModel();
                $chargeId = $feeModel->chargeRegistrationFee($newId, $userId);
                // Stage 12-G: a new pending fee genuinely needs someone on the
                // fee-collection tier (FeeController::requireCollectAccess())
                // to know it exists -- $chargeId is null when this was a
                // no-op (no registration fee configured, or already charged),
                // so nothing fires for those cases.
                if ($chargeId !== null) {
                    (new NotificationModel())->notifyRoles(
                        ['admin', 'treasurer', 'cashier', 'office_admin'],
                        "Registration fee pending",
                        "Registration fee charged for {$input['first_name']} {$input['last_name']} ({$input['member_number']}) — awaiting payment.",
                        'info', 'member_fee', $chargeId,
                        ['member_id' => $newId, 'action_url' => APP_URL . '/index.php?page=fee-charges'],
                        "member_fee_charged:{$chargeId}"
                    );
                }

                Session::flash('success',
                    "Member {$input['member_number']} added successfully. " .
                    "Compulsory Savings Account {$result['account_number']} created.");
                $this->redirect(APP_URL . '/index.php?page=member-view&id=' . $newId);
            } catch (Throwable $e) {
                Session::flash('error', 'Failed to save member: ' . $e->getMessage());
                Session::flash('form_old', $input);
                $this->redirect(APP_URL . '/index.php?page=member-add');
            }
        } else {
            unset($input['member_number'], $input['created_by']);

            // Stage 6-C: only establish manual authority when the Edit
            // form's status <select> actually submitted a value different
            // from the member's existing one -- i.e. the admin genuinely
            // changed it, not merely left the correctly-preselected
            // current value in place while editing an unrelated field.
            // When it matches, 'status_source' is deliberately omitted
            // from $input so Model::update()'s dynamic SET clause never
            // touches that column, leaving whatever it already was
            // (automatic or manual) exactly as-is -- never silently reset.
            if ($input['status'] !== ($existing['status'] ?? null)) {
                $input['status_source'] = 'manual';
            }

            if ($this->model->update($id, $input)) {
                $this->model->log($userId, 'member_updated',
                    "Updated member {$existing['member_number']} — {$input['first_name']} {$input['last_name']}");
                Session::flash('success', 'Member record updated successfully.');
            } else {
                Session::flash('error', 'No changes were saved.');
            }
            $this->redirect(APP_URL . '/index.php?page=member-view&id=' . $id);
        }
    }

    // ----------------------------------------------------------------
    // INPUT COLLECTION
    //
    // Stage 6-A: the status <select> now always renders all three real
    // options (active/inactive/dormant) with the member's true current
    // value preselected (app/views/members/form.php), so a normal submit
    // always carries a valid, correct status. $currentStatus is the
    // fallback used only if the posted value is somehow missing/invalid
    // (e.g. a stale/tampered form) -- it preserves whatever the member's
    // status already was rather than silently resetting to 'active',
    // closing the Stage 6 audit's §8 finding (unrelated edits silently
    // reactivating a dormant member).
    // ----------------------------------------------------------------
    private function collectInput(string $currentStatus = 'active'): array
    {
        $s = fn(string $k, string $def = '') => $this->sanitize($_POST[$k] ?? $def);

        // Account number: admin may enter manually or leave blank for auto-suggest
        $accountNumber = trim($s('account_number'));

        return [
            'account_number'      => $accountNumber ?: null,
            // Uppercased for display uniformity across the members list --
            // the club's spreadsheets mix Title Case and ALL CAPS entry by
            // entry, which read as inconsistent side by side.
            'first_name'          => strtoupper($s('first_name')),
            'last_name'           => strtoupper($s('last_name')),
            'gender'              => $s('gender'),
            'date_of_birth'       => $s('date_of_birth') ?: null,
            'phone'               => $s('phone'),
            'email'               => strtolower(trim($s('email'))) ?: null,
            'national_id'         => $s('national_id'),
            'station'             => $s('station') ?: null,
            'present_address'     => $s('present_address') ?: null,
            'home_address'        => $s('home_address') ?: null,
            'address'             => $s('present_address') ?: null,
            'next_of_kin_name'    => $s('next_of_kin_name') ?: null,
            'next_of_kin_phone'   => $s('next_of_kin_phone') ?: null,
            'next_of_kin_address' => $s('next_of_kin_address') ?: null,
            'next_of_kin_relation'=> $this->resolveRelation($s('next_of_kin_relation'), $s('next_of_kin_relation_other')),
            'join_date'           => $s('join_date') ?: date('Y-m-d'),
            'status'              => in_array($_POST['status'] ?? '', ['active','inactive','dormant'], true)
                                      ? $_POST['status'] : $currentStatus,
        ];
    }

    /**
     * If relation is "Other" and a custom value was typed, use the custom value.
     */
    private function resolveRelation(string $relation, string $other): ?string
    {
        if ($relation === 'Other' && trim($other) !== '') {
            return trim($other);
        }
        return $relation ?: null;
    }

    // ----------------------------------------------------------------
    // VALIDATION
    // ----------------------------------------------------------------
    private function validate(array $d, ?int $editId = null): array
    {
        $e = [];

        if (empty($d['first_name'])) {
            $e['first_name'] = 'First name is required.';
        } elseif (strlen($d['first_name']) < 2) {
            $e['first_name'] = 'First name must be at least 2 characters.';
        }

        if (empty($d['last_name'])) {
            $e['last_name'] = 'Last name is required.';
        } elseif (strlen($d['last_name']) < 2) {
            $e['last_name'] = 'Last name must be at least 2 characters.';
        }

        if (!in_array($d['gender'], ['Male','Female','Other'], true)) {
            $e['gender'] = 'Please select a gender.';
        }

        if (!empty($d['date_of_birth'])) {
            $dob = DateTime::createFromFormat('Y-m-d', $d['date_of_birth']);
            if (!$dob || $dob >= new DateTime()) {
                $e['date_of_birth'] = 'Enter a valid date of birth (must be in the past).';
            }
        }

        if (empty($d['phone'])) {
            $e['phone'] = 'Phone number is required.';
        } elseif (!preg_match('/^[+0-9][\d\s\-().]{6,19}$/', $d['phone'])) {
            $e['phone'] = 'Enter a valid phone number.';
        } elseif ($this->model->phoneExists($d['phone'], $editId)) {
            $e['phone'] = 'This phone number is already registered.';
        }

        if (!empty($d['email']) && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $e['email'] = 'Enter a valid email address.';
        }

        if (empty($d['national_id'])) {
            $e['national_id'] = 'National ID is required.';
        } elseif (strlen($d['national_id']) < 5) {
            $e['national_id'] = 'National ID must be at least 5 characters.';
        }

        if (!empty($d['join_date'])) {
            if (!DateTime::createFromFormat('Y-m-d', $d['join_date'])) {
                $e['join_date'] = 'Enter a valid join date.';
            }
        }

        // Account number — optional but must be unique if provided
        if (!empty($d['account_number'])) {
            if (!preg_match('/^[A-Za-z0-9\-\/]+$/', $d['account_number'])) {
                $e['account_number'] = 'Account number may only contain letters, numbers, hyphens, and forward slashes.';
            } elseif ($this->model->accountNumberExists($d['account_number'], $editId)) {
                $e['account_number'] = 'This account number is already assigned to another member.';
            }
        }

        return $e;
    }

    // ----------------------------------------------------------------
    // CSRF HELPERS
    // ----------------------------------------------------------------
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
        if ($id < 1) { $this->abort404(); }
        $member = $this->model->find($id);
        if (!$member) { $this->abort404(); }
        return $member;
    }

    private function abort404(): never
    {
        http_response_code(404);
        require VIEW_PATH . '/errors/404.php';
        exit;
    }
}
