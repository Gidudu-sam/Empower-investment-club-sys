<?php
/**
 * SavingsAccountController — Savings Accounts overview/register, account
 * opening (voluntary/joint/corporate), account details, statement, member
 * consolidated summary, and (Stage 5B) live deposit/withdrawal recording.
 *
 * Compulsory accounts are never manually opened here — they are created
 * automatically at member registration (Stage 3).
 *
 * Stage 5B deposit/withdrawal design (account-first, never member-first):
 * every deposit/withdrawal form is entered FROM a specific account's own
 * View page, so `account_id` always comes from the URL and is re-loaded
 * fresh from the database on every request — never trusted from a hidden
 * form field alone. Corporate accounts are NOT supported by this
 * workflow: `savings.member_id` is NOT NULL, but a corporate account has
 * no member holder at all (only an organization holder, plus optional
 * non-owning representatives per OrganizationModel's docblock) — there is
 * no existing architecture that says which member is authorized to
 * transact on a corporate account's behalf. Rather than invent one, the
 * deposit/withdrawal actions stay disabled for corporate accounts (see
 * view.php) and this is documented in the Stage 5B report as a gap, not
 * fixed here. Only 'active' accounts may transact — the literal meaning
 * of the status field, not an invented policy.
 *
 * View: admin/treasurer/viewer. Open account / transact: admin/treasurer.
 */
class SavingsAccountController extends Controller
{
    private MemberSavingsAccountModel $accountModel;
    private SavingsAccountHolderModel $holderModel;
    private OrganizationModel $organizationModel;

    public function __construct()
    {
        Session::requireAuth();

        // Stage 23: Secretary and Vice Chairman both need view access to
        // savings account information -- brief's explicit "Records/
        // Oversight" (Secretary) and broader oversight (Vice Chairman)
        // requirements. Neither role is added to any write/transact/
        // closure-approval gate below -- view only.
        if (!Session::hasRole(['admin', 'treasurer', 'cashier', 'viewer', 'chairman', 'office_admin', 'secretary', 'vice_chairman'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view savings accounts.');
        }

        $this->accountModel = new MemberSavingsAccountModel();
        $this->holderModel = new SavingsAccountHolderModel();
        $this->organizationModel = new OrganizationModel();
    }

    /** Opening a new account (voluntary/joint/corporate) — setup-tier, not a routine transaction.
     *  Office Administrator (role identifier 'office_admin', renamed from the original
     *  'front_desk') explicitly owns account setup in the six-role model. */
    private function requireWriteAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer', 'office_admin'])) {
            http_response_code(403);
            die('Access denied. Admin, Treasurer, or Office Administrator privileges required.');
        }
    }

    /** Stage 1 security remediation: deposit/withdrawal on an existing account — a routine
     *  front-line transaction, so cashier is included alongside admin/treasurer. Withdrawal
     *  stays scoped to this narrower gate deliberately -- paying money out is governed by its
     *  own tightly-controlled policy engine (Stage 17E/19C) and is a separate authority from
     *  the "record a payment received" capability below. */
    private function requireTransactAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer', 'cashier'])) {
            http_response_code(403);
            die('Access denied. Admin, Treasurer, or Cashier privileges required.');
        }
    }

    /** Payment-recording policy alignment: recording a deposit (money received) is a
     *  front-line collection duty Office Administrator also performs (registration/admin
     *  collections, savings) -- distinct from withdrawal (money paid out), which stays on
     *  requireTransactAccess() above and does NOT include Office Administrator. */
    private function requireDepositAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer', 'cashier', 'office_admin'])) {
            http_response_code(403);
            die('Access denied. Admin, Treasurer, Cashier, or Office Administrator privileges required.');
        }
    }

    /** Stage B/F: recording a Historical Balance Brought Forward is a
     *  judgment call about historical fact, not a routine payment
     *  collection -- deliberately narrower than requireDepositAccess()
     *  (excludes cashier and office_admin), matching the existing
     *  adjustment-style write gates elsewhere in this app (admin +
     *  treasurer only). Approved for this stage: no maker-checker
     *  approval step exists yet, so this same gate covers both entry and
     *  reversal. */
    private function requireBroughtForwardAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer'])) {
            http_response_code(403);
            die('Access denied. Admin or Treasurer privileges required to record a Historical Balance Brought Forward.');
        }
    }

    /** Stage FD-2 — three narrow, separate gates (not one shared
     *  "savings management" check) so the maker-checker separation the
     *  stage requires is enforced by construction, not convention. The
     *  System Administrator role is deliberately excluded from all three,
     *  matching the same rule already enforced identically for loan and
     *  voucher approval elsewhere in this system. */
    private function requireFdClosureRequestAccess(): void
    {
        if (!Session::hasRole(['admin', 'office_admin'])) {
            http_response_code(403);
            die('Access denied. Admin or Office Administrator privileges required to request Fixed Deposit closure.');
        }
    }

    private function requireFdClosureApproveAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer'])) {
            http_response_code(403);
            die('Access denied. Admin or Treasurer privileges required to approve Fixed Deposit closure.');
        }
    }

    private function requireFdPayoutAccess(): void
    {
        if (!Session::hasRole(['admin', 'cashier'])) {
            http_response_code(403);
            die('Access denied. Admin or Cashier privileges required to record a Fixed Deposit payout.');
        }
    }

    /** Stage 11 — same three role gates as FD-2 (same roles, same
     *  System Administrator exclusion), kept as separate methods rather than
     *  reusing the Fd-named ones above so neither side's naming becomes
     *  misleading; the role arrays are identical by design. */
    private function requireClosureRequestAccess(): void
    {
        if (!Session::hasRole(['admin', 'office_admin'])) {
            http_response_code(403);
            die('Access denied. Admin or Office Administrator privileges required to request account closure.');
        }
    }

    private function requireClosureApproveAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer'])) {
            http_response_code(403);
            die('Access denied. Admin or Treasurer privileges required to approve account closure.');
        }
    }

    private function requireClosureSettleAccess(): void
    {
        if (!Session::hasRole(['admin', 'cashier'])) {
            http_response_code(403);
            die('Access denied. Admin or Cashier privileges required to settle account closure.');
        }
    }

    // ----------------------------------------------------------------
    // OVERVIEW + REGISTER (one page, matching the approved mockup)
    // ----------------------------------------------------------------
    public function overview(): void
    {
        // Stage FD-2: idempotent, transaction-free sync -- mirrors
        // LoanModel::syncOverdueStatus()'s exact precedent (a plain bulk
        // UPDATE run on read, not a new scheduler this app doesn't have).
        $this->accountModel->syncMaturedFixedDeposits();

        $type   = trim($_GET['type'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $search = trim($_GET['search'] ?? '');

        $accounts = $this->accountModel->getAccounts(array_filter([
            'account_type' => $type ?: null,
            'status'       => $status ?: null,
        ]));

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $accounts = array_values(array_filter($accounts, function ($a) use ($needle) {
                $holders = $this->holderModel->getAccountHolders((int)$a['id']);
                $holderText = implode(' ', array_map(fn($h) => ($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? '') . ' ' . ($h['organization_name'] ?? ''), $holders));
                return str_contains(mb_strtolower($a['account_number']), $needle) || str_contains(mb_strtolower($holderText), $needle);
            }));
        }

        // Attach holder-label + balance for display (register table row shape)
        foreach ($accounts as &$a) {
            $holders = $this->holderModel->getAccountHolders((int)$a['id']);
            $a['holder_label'] = $this->holderLabel($holders);
            $a['balance'] = $this->accountModel->getAccountBalance((int)$a['id']);
        }
        unset($a);

        $this->render('savings-accounts/overview', [
            'pageTitle' => 'Savings Accounts',
            'typeTotals' => $this->accountModel->getAccountTypeTotals(),
            'accounts' => $accounts,
            'filters' => compact('type', 'status', 'search'),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    private function holderLabel(array $holders): string
    {
        $parts = [];
        foreach ($holders as $h) {
            if ($h['organization_name'] ?? null) {
                $parts[] = $h['organization_name'];
            } elseif (($h['first_name'] ?? null) !== null) {
                $parts[] = trim($h['first_name'] . ' ' . $h['last_name']);
            }
        }
        return implode(' & ', $parts);
    }

    // ----------------------------------------------------------------
    // OPEN ACCOUNT — type selection
    // ----------------------------------------------------------------
    public function openSelect(): void
    {
        $this->requireWriteAccess();
        $this->render('savings-accounts/open-form', [
            'pageTitle' => 'Open Savings Account',
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    // ----------------------------------------------------------------
    // VOLUNTARY
    // ----------------------------------------------------------------
    public function voluntaryForm(): void
    {
        $this->requireWriteAccess();
        $this->render('savings-accounts/voluntary-form', [
            'pageTitle' => 'Open Voluntary Savings Account',
            'members'   => $this->allMembers(),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function voluntaryStore(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-voluntary');
            return;
        }

        try {
            $memberId = (int)($_POST['member_id'] ?? 0);
            if ($memberId <= 0) {
                throw new InvalidArgumentException('Please select a member.');
            }
            $openedDate = $_POST['opened_date'] ?: date('Y-m-d');

            $accountId = $this->accountModel->createAccount(
                ['account_type' => 'voluntary', 'opened_date' => $openedDate],
                [['member_id' => $memberId, 'role' => 'primary']],
                (int)Session::get('user_id')
            );

            Session::flash('success', 'Voluntary savings account created.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $accountId);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=savings-account-voluntary');
        }
    }

    // ----------------------------------------------------------------
    // FIXED DEPOSIT (2026-09)
    //
    // Bank-style lump-sum term deposit -- a sibling account type.
    // Locked business rules: single lump-sum deposit only, no
    // top-ups, no early withdrawal, manual-only renewal, simple annual
    // interest. The single authoritative list of offered terms lives here
    // -- both the form and server-side validation read this one array, so
    // adding a term later means editing one line, not restructuring
    // anything.
    // ----------------------------------------------------------------
    private const FIXED_DEPOSIT_TERMS = [3, 6, 12, 18, 24];

    public function fixedDepositForm(): void
    {
        $this->requireWriteAccess();
        $defaultRate = (new SettingsModel())->get('fixed_deposit_default_rate_pa', '10');
        $this->render('savings-accounts/fixed-deposit-form', [
            'pageTitle'   => 'Open Fixed Deposit Account',
            'members'     => $this->allMembers(),
            'terms'       => self::FIXED_DEPOSIT_TERMS,
            'defaultRate' => (float)$defaultRate,
            'csrfToken'   => $this->getCsrf(),
        ]);
    }

    public function fixedDepositStore(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-fixed-deposit');
            return;
        }

        try {
            $memberId = (int)($_POST['member_id'] ?? 0);
            if ($memberId <= 0) {
                throw new InvalidArgumentException('Please select a member.');
            }

            $principal = (float)($_POST['principal_amount'] ?? 0);
            if ($principal <= 0) {
                throw new InvalidArgumentException('Principal amount must be greater than zero.');
            }

            $termMonths = (int)($_POST['term_months'] ?? 0);
            if (!in_array($termMonths, self::FIXED_DEPOSIT_TERMS, true)) {
                throw new InvalidArgumentException('Please select a valid term.');
            }

            $depositDate = trim($_POST['deposit_date'] ?? '') ?: date('Y-m-d');
            if (!DateTime::createFromFormat('Y-m-d', $depositDate)) {
                throw new InvalidArgumentException('Please enter a valid deposit date.');
            }

            // Authoritative rate: whatever the operator submits is what's
            // agreed and locked on this account permanently -- the
            // "suggested" default rate shown on the form is a starting
            // point only, never re-derived or trusted for content beyond
            // this one submission (mirrors the Stage 9.1 suggested/
            // approved-rate pattern already proven for loans).
            $rate = (float)($_POST['interest_rate'] ?? 0);
            if ($rate < 0) {
                throw new InvalidArgumentException('Interest rate cannot be negative.');
            }

            $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
            if (!in_array($paymentMethod, ['Cash', 'Airtel Money', 'MTN Mobile Money', 'Bank Transfer', 'Cheque', 'Other'], true)) {
                throw new InvalidArgumentException('Please select a valid payment method.');
            }

            // Server-side authoritative calculation -- never trusts any
            // maturity_date/expected_interest/expected_maturity_amount a
            // client might submit; the live preview on the form is a
            // convenience only.
            $calc = $this->accountModel->calculateFixedDeposit($principal, $rate, $termMonths, $depositDate);

            $result = $this->accountModel->createFixedDepositAccount($calc, $memberId, $paymentMethod, (int)Session::get('user_id'));

            Session::flash('success', "Fixed Deposit account opened. Principal Shs " . number_format($principal, 2) . " posted as journal entry {$result['entry_number']}.");
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $result['account_id']);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=savings-account-fixed-deposit');
        }
    }

    // ----------------------------------------------------------------
    // JOINT
    // ----------------------------------------------------------------
    public function jointForm(): void
    {
        $this->requireWriteAccess();
        $this->render('savings-accounts/joint-form', [
            'pageTitle' => 'Open Joint Savings Account',
            'members'   => $this->allMembers(),
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function jointStore(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-joint');
            return;
        }

        try {
            $memberIds = array_filter(array_map('intval', $_POST['member_ids'] ?? []));
            $primaryId = (int)($_POST['primary_member_id'] ?? 0);
            $openedDate = $_POST['opened_date'] ?: date('Y-m-d');

            if (count($memberIds) < 2) {
                throw new InvalidArgumentException('A joint account requires at least two members.');
            }

            $holders = [];
            foreach ($memberIds as $mid) {
                $holders[] = ['member_id' => $mid, 'role' => ($mid === $primaryId) ? 'primary' : 'joint'];
            }
            // If no primary was explicitly designated, the first selected member is primary.
            if (!in_array('primary', array_column($holders, 'role'), true)) {
                $holders[0]['role'] = 'primary';
            }

            $accountId = $this->accountModel->createAccount(
                ['account_type' => 'joint', 'opened_date' => $openedDate],
                $holders,
                (int)Session::get('user_id')
            );

            Session::flash('success', 'Joint savings account created.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $accountId);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=savings-account-joint');
        }
    }

    // ----------------------------------------------------------------
    // CORPORATE
    // ----------------------------------------------------------------
    public function corporateForm(): void
    {
        $this->requireWriteAccess();
        $this->render('savings-accounts/corporate-form', [
            'pageTitle'     => 'Open Corporate Savings Account',
            'organizations' => $this->organizationModel->getOrganizations(),
            'members'       => $this->allMembers(),
            'csrfToken'     => $this->getCsrf(),
        ]);
    }

    public function corporateStore(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-corporate');
            return;
        }

        try {
            $userId = (int)Session::get('user_id');
            $organizationId = (int)($_POST['organization_id'] ?? 0);

            if ($organizationId <= 0) {
                // Creating a new organization inline
                $organizationId = $this->organizationModel->createOrganization([
                    'name'                => $_POST['org_name'] ?? '',
                    'registration_number' => $_POST['org_registration_number'] ?? '',
                    'contact_phone'       => $_POST['org_contact_phone'] ?? '',
                    'contact_email'       => $_POST['org_contact_email'] ?? '',
                    'address'             => $_POST['org_address'] ?? '',
                ]);
            }

            $openedDate = $_POST['opened_date'] ?: date('Y-m-d');

            $accountId = $this->accountModel->createAccount(
                ['account_type' => 'corporate', 'opened_date' => $openedDate],
                [['organization_id' => $organizationId, 'role' => 'organization']],
                $userId
            );

            // Representatives: parallel arrays from repeated form rows.
            // Not required by the current model (no minimum-count invariant
            // exists in OrganizationModel/SavingsAccountHolderModel today —
            // verified, not assumed — see Stage 4 report), so zero
            // representatives is accepted here too.
            $names = $_POST['rep_full_name'] ?? [];
            foreach ($names as $i => $name) {
                $name = trim($name);
                if ($name === '') {
                    continue;
                }
                $this->organizationModel->addRepresentative($organizationId, [
                    'member_id'               => !empty($_POST['rep_member_id'][$i]) ? (int)$_POST['rep_member_id'][$i] : null,
                    'full_name'               => $name,
                    'phone'                   => $_POST['rep_phone'][$i] ?? '',
                    'role'                    => $_POST['rep_role'][$i] ?? '',
                    'is_authorized_signatory' => !empty($_POST['rep_signatory'][$i]),
                ]);
            }

            Session::flash('success', 'Corporate savings account created.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $accountId);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=savings-account-corporate');
        }
    }

    // ----------------------------------------------------------------
    // DEPOSIT / WITHDRAWAL (Stage 5B) — always entered from a specific
    // account's own page; account_id always re-loaded fresh from the DB,
    // never trusted from a posted/hidden field alone. Corporate accounts
    // are rejected here (see class docblock for why).
    // ----------------------------------------------------------------

    /** Loads and validates the account for a deposit/withdrawal request. Returns [account, holders] or null (caller must redirect/404). */
    private function loadTransactableAccount(int $id): ?array
    {
        $account = $this->accountModel->getAccount($id);
        if (!$account) {
            return null;
        }
        return ['account' => $account, 'holders' => $this->holderModel->getAccountHolders($id)];
    }

    public function depositForm(): void
    {
        $this->requireDepositAccess();
        $id = (int)($_GET['id'] ?? 0);
        $loaded = $this->loadTransactableAccount($id);
        if (!$loaded) {
            http_response_code(404);
            die('Savings account not found.');
        }
        $account = $loaded['account'];

        if ($account['account_type'] === 'corporate') {
            Session::flash('error', 'Deposits cannot be recorded against a corporate account through this workflow yet — no holder-authorization rule exists for corporate accounts (see Stage 5B report).');
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
            return;
        }
        if ($account['account_type'] === 'fixed_deposit') {
            Session::flash('error', 'Fixed Deposit accounts accept one initial lump-sum deposit only, recorded at account opening. Additional deposits are not permitted.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
            return;
        }
        if ($account['status'] !== 'active') {
            Session::flash('error', 'This account is not active and cannot receive a deposit.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
            return;
        }

        $this->render('savings-accounts/transact-form', [
            'pageTitle'    => 'Record Deposit — ' . $account['account_number'],
            'mode'         => 'deposit',
            'account'      => $account,
            'holders'      => $loaded['holders'],
            'balance'      => $this->accountModel->getAccountBalance($id),
            'csrfToken'    => $this->getCsrf(),
        ]);
    }

    /** Fixed Deposit accepts exactly one deposit -- its opening principal,
     *  recorded atomically by MemberSavingsAccountModel::createFixedDepositAccount()
     *  at account-creation time, never through this generic deposit flow.
     *  Rejected here unconditionally and server-side, regardless of what
     *  the UI shows or hides -- a direct POST to savings-account-deposit-store
     *  for a fixed_deposit account must fail exactly like this. */
    private function validateFixedDepositDeposit(array $account): void
    {
        if ($account['account_type'] === 'fixed_deposit') {
            throw new InvalidArgumentException('Fixed Deposit accounts accept one initial lump-sum deposit only, recorded at account opening. Additional deposits are not permitted.');
        }
    }

    public function depositStore(): void
    {
        $this->requireDepositAccess();
        $id = (int)($_POST['account_id'] ?? 0);
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-deposit&id=' . $id);
            return;
        }

        try {
            [$memberId, $account] = $this->resolveTransactionMember($id, 'deposit');
            $input = $this->collectDepositInput($account, $memberId);

            $savingsModel = new SavingsModel();
            $input['receipt_number'] = $savingsModel->generateReceiptNumber();
            $input['recorded_by']    = (int)Session::get('user_id');
            $input['financial_year'] = date('Y', strtotime($input['transaction_date']));

            $posted = $savingsModel->recordDepositWithPosting($input, (int)Session::get('user_id'));

            Session::flash('success', "Deposit of Shs " . number_format($input['amount'], 2) . " recorded ({$input['receipt_number']}), posted as journal entry {$posted['entry_number']}.");
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
        } catch (PDOException $e) {
            Session::flash('error', 'Could not record deposit: ' . $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=savings-account-deposit&id=' . $id);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=savings-account-deposit&id=' . $id);
        }
    }

    // ----------------------------------------------------------------
    // Historical Balance Brought Forward (B/F)
    //
    // Subledger-only, per the approved design: no journal is ever
    // created (SavingsModel::createBroughtForward() never calls
    // postDeposit()/recordDepositWithPosting()). Scoped to compulsory
    // and voluntary accounts only for this initial implementation --
    // the same two individual-ownership account types the deposit/
    // withdrawal workflow already treats as the "normal" case;
    // corporate is already rejected unconditionally by
    // resolveTransactionMember(), and joint/fixed_deposit are
    // deliberately not offered here (not part of the approved scope,
    // and a lump-sum Fixed Deposit's "opening balance" is its principal,
    // recorded at account creation, not this feature).
    // ----------------------------------------------------------------

    public function bfForm(): void
    {
        $this->requireBroughtForwardAccess();
        $id = (int)($_GET['id'] ?? 0);
        $loaded = $this->loadTransactableAccount($id);
        if (!$loaded) {
            http_response_code(404);
            die('Savings account not found.');
        }
        $account = $loaded['account'];

        if (!in_array($account['account_type'], ['compulsory', 'voluntary'], true)) {
            Session::flash('error', 'Historical Balance Brought Forward is only available for Compulsory or Voluntary savings accounts.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
            return;
        }
        if ($account['status'] !== 'active') {
            Session::flash('error', 'This account is not active.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
            return;
        }

        $savingsModel = new SavingsModel();
        if ($savingsModel->hasBroughtForward($id)) {
            Session::flash('error', 'This account already has a Historical Balance Brought Forward on record.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
            return;
        }

        $this->render('savings-accounts/bf-form', [
            'pageTitle' => 'Balance Brought Forward — ' . $account['account_number'],
            'account'   => $account,
            'holders'   => $loaded['holders'],
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    private function collectBroughtForwardInput(int $memberId, int $accountId): array
    {
        $amount = (float)($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        $effectiveDate = trim($_POST['effective_date'] ?? '');
        if ($effectiveDate === '' || !DateTime::createFromFormat('Y-m-d', $effectiveDate)) {
            throw new InvalidArgumentException('Please enter a valid historical effective (as-of) date.');
        }
        if ($effectiveDate > date('Y-m-d')) {
            throw new InvalidArgumentException('The effective date cannot be in the future.');
        }

        // Stage B/F period-date correction: the historical period is now
        // captured as two structured dates (Period From / Period To)
        // instead of a free-text textarea. This is a controller-only
        // change -- SavingsModel::createBroughtForward() still just
        // receives a plain 'notes' string exactly as before; only how
        // that string is produced has changed, here.
        $periodFrom = trim($_POST['period_from'] ?? '');
        if ($periodFrom === '' || !DateTime::createFromFormat('Y-m-d', $periodFrom)) {
            throw new InvalidArgumentException('Please enter a valid Period From date.');
        }
        $periodTo = trim($_POST['period_to'] ?? '');
        if ($periodTo === '' || !DateTime::createFromFormat('Y-m-d', $periodTo)) {
            throw new InvalidArgumentException('Please enter a valid Period To date.');
        }
        if ($periodFrom > $periodTo) {
            throw new InvalidArgumentException('Period From cannot be later than Period To.');
        }

        $notes = sprintf(
            'Historical savings accumulated from %s to %s, consolidated into this Balance Brought Forward entry.',
            date('d F Y', strtotime($periodFrom)),
            date('d F Y', strtotime($periodTo))
        );

        return [
            'member_id'          => $memberId,
            'savings_account_id' => $accountId,
            'amount'             => $amount,
            'transaction_date'   => $effectiveDate,
            'notes'              => $notes,
        ];
    }

    public function bfStore(): void
    {
        $this->requireBroughtForwardAccess();
        $id = (int)($_POST['account_id'] ?? 0);
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-bf&id=' . $id);
            return;
        }

        try {
            // resolveTransactionMember() derives the member from the
            // account's own holder record -- it is never trusted from a
            // posted member_id -- so an attempt to record a B/F for
            // member A against member B's savings_account_id resolves to
            // member B (the account's real owner) regardless of what, if
            // anything, was submitted alongside it.
            [$memberId, $account] = $this->resolveTransactionMember($id, 'brought forward');
            if (!in_array($account['account_type'], ['compulsory', 'voluntary'], true)) {
                throw new InvalidArgumentException('Historical Balance Brought Forward is only available for Compulsory or Voluntary savings accounts.');
            }
            $input = $this->collectBroughtForwardInput($memberId, $id);

            $savingsModel = new SavingsModel();
            $userId = (int)Session::get('user_id');
            $result = $savingsModel->createBroughtForward($input, $userId);

            $member = (new MemberModel())->find($memberId);
            $memberLabel = $member ? trim($member['first_name'] . ' ' . $member['last_name']) : ('member #' . $memberId);
            $enteredBy = Session::get('user_name') ?? ('user #' . $userId);

            // Section 20's required confirmation content (member, account,
            // amount, effective date, receipt/reference, entered by,
            // journal status) as one flash message -- the account view
            // page immediately below it also shows the same new ledger
            // row with every one of these facts, so no separate
            // confirmation page is introduced for this smallest-practical
            // implementation.
            Session::flash('success',
                "Balance Brought Forward recorded — Member: {$memberLabel} — Account: {$account['account_number']} — " .
                "Amount: Shs " . number_format($input['amount'], 2) . " — Effective: " . date('d M Y', strtotime($input['transaction_date'])) . " — " .
                "Receipt: {$result['receipt_number']} — Entered by: {$enteredBy} — " .
                "Journal status: No GL Journal — Historical Subledger Entry."
            );
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
        } catch (PDOException $e) {
            Session::flash('error', 'Could not record Balance Brought Forward: ' . $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=savings-account-bf&id=' . $id);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=savings-account-bf&id=' . $id);
        }
    }

    public function bfReverseForm(): void
    {
        $this->requireBroughtForwardAccess();
        $accountId = (int)($_GET['id'] ?? 0);
        $loaded = $this->loadTransactableAccount($accountId);
        if (!$loaded) {
            http_response_code(404);
            die('Savings account not found.');
        }

        $savingsModel = new SavingsModel();
        if (!$savingsModel->hasBroughtForward($accountId)) {
            Session::flash('error', 'This account has no active Historical Balance Brought Forward to reverse.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $accountId);
            return;
        }

        $stmt = $this->db()->prepare(
            "SELECT * FROM `savings` WHERE savings_account_id = ? AND transaction_type = 'opening_balance'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$accountId]);
        $bfRow = $stmt->fetch();

        $this->render('savings-accounts/bf-reverse-form', [
            'pageTitle' => 'Reverse Balance Brought Forward — ' . $loaded['account']['account_number'],
            'account'   => $loaded['account'],
            'bfRow'     => $bfRow,
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function bfReverseStore(): void
    {
        $this->requireBroughtForwardAccess();
        $accountId = (int)($_POST['account_id'] ?? 0);
        $savingsId = (int)($_POST['savings_id'] ?? 0);
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-bf-reverse&id=' . $accountId);
            return;
        }

        try {
            $account = $this->accountModel->getAccount($accountId);
            if (!$account) {
                throw new InvalidArgumentException('Savings account not found.');
            }
            $reason = trim($_POST['reason'] ?? '');

            $savingsModel = new SavingsModel();
            $row = $savingsModel->find($savingsId);
            if (!$row || (int)$row['savings_account_id'] !== $accountId) {
                throw new InvalidArgumentException('That Balance Brought Forward record does not belong to this account.');
            }

            $savingsModel->reverseBroughtForward($savingsId, (int)Session::get('user_id'), $reason);

            Session::flash('success', 'The Historical Balance Brought Forward has been reversed. The original entry remains in the ledger for audit purposes.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $accountId);
        } catch (PDOException $e) {
            Session::flash('error', 'Could not reverse Balance Brought Forward: ' . $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=savings-account-bf-reverse&id=' . $accountId);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=savings-account-bf-reverse&id=' . $accountId);
        }
    }

    public function withdrawalForm(): void
    {
        $this->requireTransactAccess();
        $id = (int)($_GET['id'] ?? 0);
        $loaded = $this->loadTransactableAccount($id);
        if (!$loaded) {
            http_response_code(404);
            die('Savings account not found.');
        }
        $account = $loaded['account'];

        if ($account['account_type'] === 'corporate') {
            Session::flash('error', 'Withdrawals cannot be recorded against a corporate account through this workflow yet — no holder-authorization rule exists for corporate accounts (see Stage 5B report).');
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
            return;
        }
        if ($account['status'] !== 'active') {
            Session::flash('error', 'This account is not active and cannot process a withdrawal.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
            return;
        }

        // Stage 19C: every withdrawal must be governed by an active,
        // enabled withdrawal policy for this account's type -- an account
        // type with no configured policy (e.g. joint) is rejected here,
        // before a form is even shown, rather than silently falling back
        // to an unrestricted withdrawal.
        $policyModel = new WithdrawalPolicyModel();
        $policy = $policyModel->getActivePolicy($account['account_type'], date('Y-m-d'));
        if (!$policy || empty($policy['withdrawal_enabled'])) {
            Session::flash('error', "Withdrawals are not currently permitted for {$account['account_type']} accounts — no active withdrawal policy is configured for this account type.");
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
            return;
        }

        $balance = $this->accountModel->getAccountBalance($id);

        $this->render('savings-accounts/transact-form', [
            'pageTitle'     => 'Record Withdrawal — ' . $account['account_number'],
            'mode'          => 'withdrawal',
            'account'       => $account,
            'holders'       => $loaded['holders'],
            'balance'       => $balance,
            'policy'        => $policy,
            'maxWithdrawal' => $policyModel->calculateMaximumWithdrawal($balance, $policy),
            'csrfToken'     => $this->getCsrf(),
        ]);
    }

    /**
     * Stage 19C: this is no longer an independent withdrawal-writing path.
     * Every withdrawal, from every entry point, must be processed by the
     * same authoritative, policy-driven engine established in Stage 17
     * Part E (WithdrawalModel::processAnnualCompulsory()/processVoluntary())
     * -- this method's job is now only to resolve who is transacting and
     * on which account, map that onto WithdrawalModel's expected input
     * shape, and delegate. It performs no balance/percentage/frequency
     * calculation of its own (§12 "No Duplicate Business Rules").
     */
    public function withdrawalStore(): void
    {
        $this->requireTransactAccess();
        $id = (int)($_POST['account_id'] ?? 0);
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-withdrawal&id=' . $id);
            return;
        }

        try {
            [$memberId, $account] = $this->resolveTransactionMember($id, 'withdrawal');

            if (!in_array($account['account_type'], ['compulsory', 'voluntary'], true)) {
                // Corporate is already rejected inside resolveTransactionMember().
                // Any other type (currently: joint) has no configured
                // withdrawal policy to enforce -- reject explicitly rather
                // than silently falling back to an unrestricted withdrawal.
                throw new InvalidArgumentException("Withdrawals require an active withdrawal policy for this account type; none is configured for '{$account['account_type']}' accounts.");
            }

            // Defensive uniqueness check: WithdrawalModel resolves the
            // member's account by (member_id, account_type), not by the
            // specific account id this form was opened against. Compulsory
            // accounts are already guaranteed unique per member at
            // creation; this guard additionally protects the voluntary
            // path (which has no such uniqueness constraint) from ever
            // silently processing a withdrawal against a different account
            // than the one the user is actually viewing.
            $accountsOfType = array_values(array_filter(
                $this->accountModel->getMemberAccounts($memberId),
                fn($a) => $a['account_type'] === $account['account_type']
            ));
            if (count($accountsOfType) !== 1 || (int)$accountsOfType[0]['id'] !== (int)$account['id']) {
                throw new InvalidArgumentException('This member has more than one ' . $account['account_type'] . ' account — the withdrawal could not be uniquely resolved to this specific account. Please contact an administrator.');
            }

            $input = $this->collectWithdrawalInput($memberId);

            $withdrawalModel = new WithdrawalModel();
            $userId = (int)Session::get('user_id');

            $withdrawalId = $account['account_type'] === 'compulsory'
                ? $withdrawalModel->processAnnualCompulsory($input, $userId)
                : $withdrawalModel->processVoluntary($input, $userId);

            $withdrawal = $withdrawalModel->find($withdrawalId);
            $withdrawalModel->log($userId, 'withdrawal_processed',
                "Processed {$withdrawal['withdrawal_number']} ({$account['account_type']}) via savings account "
                . "{$account['account_number']} for member_id {$memberId} — Shs "
                . number_format((float)$withdrawal['withdrawal_amount'], 2) . " withdrawn, Shs "
                . number_format((float)$withdrawal['retained_amount'], 2) . " retained as shares");

            Session::flash('success', "Withdrawal {$withdrawal['withdrawal_number']} recorded — Shs "
                . number_format((float)$withdrawal['withdrawal_amount'], 2) . " withdrawn"
                . ($withdrawal['retained_amount'] > 0 ? ", Shs " . number_format((float)$withdrawal['retained_amount'], 2) . " converted to shares." : "."));
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=savings-account-withdrawal&id=' . $id);
        }
    }

    /**
     * Resolves and validates which member_id a deposit/withdrawal is
     * attributed to, and re-validates the account fresh from the DB
     * (never trusts account_type/status from a posted field). For an
     * individual account (compulsory/voluntary) the member is
     * unambiguous — the account's own single holder — and any posted
     * member_id is ignored entirely, closing off any tampering vector.
     * For a joint account, the posted member_id MUST be one of the
     * account's actual holders (Section 5's "verify the relevant member
     * is a valid holder" requirement) or the transaction is rejected.
     *
     * @return array{0:int, 1:array} [memberId, account]
     */
    private function resolveTransactionMember(int $accountId, string $kind): array
    {
        $account = $this->accountModel->getAccount($accountId);
        if (!$account) {
            throw new InvalidArgumentException('Savings account not found.');
        }
        if ($account['account_type'] === 'corporate') {
            throw new InvalidArgumentException('Corporate accounts are not supported by this workflow (see Stage 5B report).');
        }
        // Was previously enforced only in depositForm() (the GET page),
        // never here in the authoritative POST handler -- a direct POST to
        // depositStore() could add an extra deposit to a Fixed Deposit
        // account, which by policy accepts exactly one lump-sum principal
        // at opening. Withdrawal is unaffected: withdrawalStore() already
        // restricts its own account_type allow-list to compulsory/voluntary
        // before this method is ever reached.
        if ($account['account_type'] === 'fixed_deposit' && $kind === 'deposit') {
            throw new InvalidArgumentException('Fixed Deposit accounts accept one initial lump-sum deposit only, recorded at account opening. Additional deposits are not permitted.');
        }
        if ($account['status'] !== 'active') {
            throw new InvalidArgumentException('This account is not active.');
        }

        $holders = $this->holderModel->getAccountHolders($accountId);

        if (in_array($account['account_type'], ['compulsory', 'voluntary'], true)) {
            if (count($holders) !== 1 || empty($holders[0]['member_id'])) {
                throw new InvalidArgumentException('This account has no valid individual holder.');
            }
            return [(int)$holders[0]['member_id'], $account];
        }

        // Joint: the transacting member must be posted AND be an actual holder.
        $postedMemberId = (int)($_POST['member_id'] ?? 0);
        $validHolderIds = array_map(fn($h) => (int)$h['member_id'], array_filter($holders, fn($h) => !empty($h['member_id'])));
        if ($postedMemberId <= 0 || !in_array($postedMemberId, $validHolderIds, true)) {
            throw new InvalidArgumentException('Please select which account holder is making this ' . $kind . '.');
        }
        return [$postedMemberId, $account];
    }

    /** Field collection + validation for the deposit form (account-aware; deposits have no policy to enforce). */
    private function collectDepositInput(array $account, int $memberId): array
    {
        $amount = (float)($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
        if (!in_array($paymentMethod, ['Cash', 'Airtel Money', 'MTN Mobile Money', 'Bank Transfer', 'Cheque', 'Other'], true)) {
            throw new InvalidArgumentException('Please select a valid payment method.');
        }

        $transactionDate = trim($_POST['transaction_date'] ?? '');
        if ($transactionDate === '' || !DateTime::createFromFormat('Y-m-d', $transactionDate)) {
            throw new InvalidArgumentException('Please enter a valid transaction date.');
        }

        $this->validateFixedDepositDeposit($account);

        return [
            'member_id'          => $memberId,
            'savings_account_id' => (int)$account['id'],
            'amount'             => $amount,
            'transaction_type'   => 'deposit',
            'payment_method'     => $paymentMethod,
            'reference_number'   => trim($_POST['reference_number'] ?? '') ?: null,
            'transaction_date'   => $transactionDate,
            'notes'              => trim($_POST['notes'] ?? '') ?: null,
        ];
    }

    /**
     * Stage 19C: field collection for the withdrawal form, shaped to feed
     * directly into WithdrawalModel::processAnnualCompulsory()/
     * processVoluntary() -- the same authoritative engine WithdrawalController
     * uses. Deliberately performs NO balance/percentage/policy validation of
     * its own (§12 "No Duplicate Business Rules") -- that is entirely
     * WithdrawalModel's job, re-checked fresh under an account row lock.
     */
    private function collectWithdrawalInput(int $memberId): array
    {
        $amount = (float)($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
        if (!in_array($paymentMethod, ['Cash', 'Airtel Money', 'MTN Mobile Money', 'Bank Transfer', 'Cheque', 'Other'], true)) {
            throw new InvalidArgumentException('Please select a valid payment method.');
        }

        $transactionDate = trim($_POST['transaction_date'] ?? '');
        if ($transactionDate === '' || !DateTime::createFromFormat('Y-m-d', $transactionDate)) {
            throw new InvalidArgumentException('Please enter a valid transaction date.');
        }

        return [
            'member_id'         => $memberId,
            'requested_amount'  => $amount,
            'payment_method'    => $paymentMethod,
            'withdrawal_date'   => $transactionDate,
            'reference_number'  => trim($_POST['reference_number'] ?? '') ?: null,
            'remarks'           => trim($_POST['notes'] ?? '') ?: null,
        ];
    }

    // ----------------------------------------------------------------
    // ACCOUNT DETAILS
    // ----------------------------------------------------------------
    public function view(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $this->accountModel->syncMaturedFixedDeposits();
        $account = $this->accountModel->getAccount($id);
        if (!$account) {
            http_response_code(404);
            die('Savings account not found.');
        }

        $holders = $this->holderModel->getAccountHolders($id);
        $organization = null;
        $representatives = [];
        foreach ($holders as $h) {
            if (!empty($h['organization_id'])) {
                $organization = $this->organizationModel->getOrganization((int)$h['organization_id']);
                $representatives = $this->organizationModel->getRepresentatives((int)$h['organization_id']);
            }
        }

        // Stage 5B: transacting is only offered when it is actually safe and
        // operational -- active status (the literal meaning of the status
        // field) and not a corporate account (no holder-authorization rule
        // exists for corporate accounts yet; see class docblock).
        $canTransact = $account['status'] === 'active' && $account['account_type'] !== 'corporate';

        // Stage FD-2: the most recent closure request for this account, if
        // any -- drives the closure-status display and the "Request
        // Closure" gate for every account type other than fixed_deposit
        // this is simply null.
        $fdClosureRequest = null;
        if ($account['account_type'] === 'fixed_deposit') {
            $stmt = $this->db()->prepare(
                "SELECT * FROM fixed_deposit_closure_requests WHERE savings_account_id=? ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$id]);
            $fdClosureRequest = $stmt->fetch() ?: null;
        }

        // Stage 11 — the same idea, generalized to the other five account
        // types. eligibility is read-only and safe to compute on every view.
        $closureRequest = null;
        $closureEligibility = null;
        if (in_array($account['account_type'], SavingsAccountClosureService::ACCOUNT_TYPES, true)) {
            $stmt = $this->db()->prepare(
                "SELECT * FROM savings_account_closure_requests WHERE savings_account_id=? ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$id]);
            $closureRequest = $stmt->fetch() ?: null;
            $closureEligibility = (new SavingsAccountClosureService())->checkEligibility($account);
        }

        $this->render('savings-accounts/view', [
            'pageTitle'       => 'Account ' . $account['account_number'],
            'account'         => $account,
            'holders'         => $holders,
            'organization'    => $organization,
            'representatives' => $representatives,
            'balance'         => $this->accountModel->getAccountBalance($id),
            'totalDeposits'   => $this->accountModel->getTotalDeposits($id),
            'totalWithdrawals'=> $this->accountModel->getTotalWithdrawals($id),
            'transactions'    => $this->accountModel->getAccountTransactions($id),
            'lastActivity'    => $this->accountModel->getLastActivity($id),
            'adjustments'     => (new MemberAccountAdjustmentModel())->forAccount($id),
            'canWrite'        => Session::hasRole(['admin', 'treasurer', 'cashier']),
            'canDeposit'      => Session::hasRole(['admin', 'treasurer', 'cashier', 'office_admin']),
            'canTransact'     => $canTransact,
            'canBroughtForward' => Session::hasRole(['admin', 'treasurer']),
            'hasBroughtForward' => in_array($account['account_type'], ['compulsory', 'voluntary'], true)
                ? (new SavingsModel())->hasBroughtForward($id) : false,
            'fdClosureRequest'=> $fdClosureRequest,
            'closureRequest'     => $closureRequest,
            'closureEligibility' => $closureEligibility,
            'csrfToken'       => $this->getCsrf(),
        ]);
    }

    // ----------------------------------------------------------------
    // STATEMENT
    // ----------------------------------------------------------------
    /** Account-level statement (2026-09 period support). Always
     *  savings_account_id-scoped -- never mixes another account's
     *  transactions in, even for the same member. Defaults to the
     *  account's full history (opened_date -> today) when no period is
     *  given, computed the same way a custom period is: a real
     *  opening-balance-as-of query plus a date-bounded transaction range,
     *  replacing the previous "assume the shown rows are the whole
     *  history" approximation (which silently broke past 100 rows). */
    public function statement(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $account = $this->accountModel->getAccount($id);
        if (!$account) {
            http_response_code(404);
            die('Savings account not found.');
        }

        $dateFrom = trim($_GET['date_from'] ?? '') ?: $account['opened_date'];
        $dateTo   = trim($_GET['date_to'] ?? '') ?: date('Y-m-d');
        if (strtotime($dateFrom) === false || strtotime($dateTo) === false || strtotime($dateFrom) > strtotime($dateTo)) {
            $dateFrom = $account['opened_date'];
            $dateTo   = date('Y-m-d');
        }

        $openingBalance = $this->accountModel->accountOpeningBalanceAsOf($id, $dateFrom);
        $transactions   = $this->accountModel->getAccountTransactionsInRange($id, $dateFrom, $dateTo);
        $totalCredits   = array_sum(array_column($transactions, 'credit'));
        $totalDebits    = array_sum(array_column($transactions, 'debit'));
        $closingBalance = $openingBalance + $totalCredits - $totalDebits;

        $holders = $this->holderModel->getAccountHolders($id);
        $this->render('savings-accounts/statement', [
            'pageTitle'      => 'Statement — ' . $account['account_number'],
            'account'        => $account,
            'holderLabel'    => $this->holderLabel($holders),
            'dateFrom'       => $dateFrom,
            'dateTo'         => $dateTo,
            'openingBalance' => $openingBalance,
            'closingBalance' => $closingBalance,
            'totalCredits'   => $totalCredits,
            'totalDebits'    => $totalDebits,
            'balance'        => $closingBalance,
            'transactions'   => $transactions,
        ], null);
    }

    // ----------------------------------------------------------------
    // MEMBER CONSOLIDATED SUMMARY
    // ----------------------------------------------------------------
    public function memberSummary(): void
    {
        $memberId = (int)($_GET['member_id'] ?? 0);
        $member = (new MemberModel())->find($memberId);
        if (!$member) {
            http_response_code(404);
            die('Member not found.');
        }

        $this->render('savings-accounts/member-summary', [
            'pageTitle' => 'Savings Summary — ' . $member['first_name'] . ' ' . $member['last_name'],
            'member'    => $member,
            'summary'   => $this->accountModel->getMemberSavingsSummary($memberId),
        ]);
    }

    // ----------------------------------------------------------------
    private function allMembers(): array
    {
        return $this->db()->query("SELECT id, member_number, first_name, last_name FROM members ORDER BY first_name, last_name")->fetchAll();
    }

    private function db(): PDO
    {
        return Database::getInstance()->getConnection();
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

    // ================================================================
    // FIXED DEPOSIT MATURITY, PAYOUT & CLOSURE (Stage FD-2)
    //
    // Every rule lives in FixedDepositClosureService -- these actions only
    // authorize, validate input shape, call the service, and redirect.
    // ================================================================

    private function findClosureRequestOrAbort(int $id): array
    {
        $stmt = $this->db()->prepare(
            "SELECT r.*, a.account_number, a.status AS account_status,
                    m.first_name, m.last_name, m.member_number
             FROM `fixed_deposit_closure_requests` r
             JOIN `member_savings_accounts` a ON a.id = r.savings_account_id
             JOIN `members` m ON m.id = r.member_id
             WHERE r.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            die('Closure request not found.');
        }
        return $row;
    }

    // ---- OFFICE ADMIN: request ------------------------------------

    public function fixedDepositClosureRequest(): void
    {
        $this->requireFdClosureRequestAccess();
        $this->accountModel->syncMaturedFixedDeposits();
        $id = (int)($_GET['id'] ?? 0);
        $account = $this->accountModel->getAccount($id);
        if (!$account || $account['account_type'] !== 'fixed_deposit') {
            http_response_code(404);
            die('Fixed Deposit account not found.');
        }
        $holders = $this->holderModel->getAccountHolders($id);
        $member = $holders[0] ?? null;

        $this->render('savings-accounts/fixed-deposit-closure-request', [
            'pageTitle' => 'Request Fixed Deposit Closure — ' . $account['account_number'],
            'account'   => $account,
            'member'    => $member,
            'csrfToken' => $this->getCsrf(),
        ]);
    }

    public function fixedDepositClosureRequestStore(): void
    {
        $this->requireFdClosureRequestAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-accounts');
            return;
        }
        $id = (int)($_POST['account_id'] ?? 0);
        try {
            $service = new FixedDepositClosureService();
            $requestId = $service->requestClosure($id, (int)Session::get('user_id'));
            $account = $this->accountModel->getAccount($id);
            // Stage 12-F: mirrors the certified FD-2/loan pattern -- notify
            // the next actor in the maker-checker chain, not the requester.
            // Treasurer approval authority = requireFdClosureApproveAccess().
            (new NotificationModel())->notifyRoles(
                ['admin', 'treasurer'],
                "Fixed Deposit closure awaiting approval",
                "Fixed Deposit {$account['account_number']} closure request #{$requestId} needs your approval.",
                'info', 'fixed_deposit_closure', $requestId,
                ['action_url' => APP_URL . '/index.php?page=savings-account-fd-closure-review&id=' . $requestId],
                "fd_closure_requested:{$requestId}"
            );
            Session::flash('success', "Closure requested (request #{$requestId}). It now awaits Treasurer approval.");
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
        }
    }

    public function fixedDepositClosureCancel(): void
    {
        $this->requireFdClosureRequestAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-accounts');
            return;
        }
        $requestId = (int)($_POST['request_id'] ?? 0);
        $accountId = (int)($_POST['account_id'] ?? 0);
        try {
            (new FixedDepositClosureService())->cancel($requestId, (int)Session::get('user_id'));
            Session::flash('success', 'Closure request cancelled.');
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $accountId);
    }

    // ---- TREASURER: review / approve / reject ----------------------

    public function fixedDepositClosureQueue(): void
    {
        $this->requireFdClosureApproveAccess();
        $rows = $this->db()->query(
            "SELECT r.*, a.account_number, m.first_name, m.last_name, m.member_number
             FROM `fixed_deposit_closure_requests` r
             JOIN `member_savings_accounts` a ON a.id = r.savings_account_id
             JOIN `members` m ON m.id = r.member_id
             WHERE r.status='pending' ORDER BY r.requested_at ASC"
        )->fetchAll();

        $this->render('savings-accounts/fixed-deposit-closure-queue', [
            'pageTitle' => 'Fixed Deposit Closure Requests — Pending Approval',
            'requests'  => $rows,
        ]);
    }

    public function fixedDepositClosureReview(): void
    {
        $this->requireFdClosureApproveAccess();
        $id = (int)($_GET['id'] ?? 0);
        $request = $this->findClosureRequestOrAbort($id);

        $requestedByStmt = $this->db()->prepare("SELECT full_name FROM users WHERE id=?");
        $requestedByStmt->execute([$request['requested_by']]);

        $this->render('savings-accounts/fixed-deposit-closure-review', [
            'pageTitle'    => 'Review Closure Request #' . $id,
            'request'      => $request,
            'requestedBy'  => $requestedByStmt->fetchColumn() ?: 'Unknown',
            'currentUserId'=> (int)Session::get('user_id'),
            'csrfToken'    => $this->getCsrf(),
        ]);
    }

    public function fixedDepositClosureApprove(): void
    {
        $this->requireFdClosureApproveAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-fd-closures');
            return;
        }
        $id = (int)($_POST['request_id'] ?? 0);
        try {
            (new FixedDepositClosureService())->approve($id, (int)Session::get('user_id'));
            $request = $this->findClosureRequestOrAbort($id);
            // Notify the cashier tier (requireFdPayoutAccess()) -- the next actor.
            (new NotificationModel())->notifyRoles(
                ['admin', 'cashier'],
                "Fixed Deposit closure ready for payout",
                "Fixed Deposit {$request['account_number']} closure request #{$id} was approved and now awaits payout.",
                'success', 'fixed_deposit_closure', $id,
                ['action_url' => APP_URL . '/index.php?page=savings-account-fd-payout-form&id=' . $id],
                "fd_closure_approved:{$id}"
            );
            Session::flash('success', "Closure request #{$id} approved. It now awaits Cashier payout.");
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=savings-account-fd-closures');
    }

    public function fixedDepositClosureReject(): void
    {
        $this->requireFdClosureApproveAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-fd-closures');
            return;
        }
        $id = (int)($_POST['request_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        try {
            (new FixedDepositClosureService())->reject($id, (int)Session::get('user_id'), $reason);
            $request = $this->findClosureRequestOrAbort($id);
            // Notify the requester's tier (requireFdClosureRequestAccess()).
            (new NotificationModel())->notifyRoles(
                ['admin', 'office_admin'],
                "Fixed Deposit closure rejected",
                "Fixed Deposit {$request['account_number']} closure request #{$id} was rejected: {$reason}",
                'critical', 'fixed_deposit_closure', $id,
                ['action_url' => APP_URL . '/index.php?page=savings-account-view&id=' . $request['savings_account_id']],
                "fd_closure_rejected:{$id}"
            );
            Session::flash('success', "Closure request #{$id} rejected.");
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=savings-account-fd-closures');
    }

    // ---- CASHIER: payout --------------------------------------------

    public function fixedDepositPayoutQueue(): void
    {
        $this->requireFdPayoutAccess();
        $rows = $this->db()->query(
            "SELECT r.*, a.account_number, m.first_name, m.last_name, m.member_number
             FROM `fixed_deposit_closure_requests` r
             JOIN `member_savings_accounts` a ON a.id = r.savings_account_id
             JOIN `members` m ON m.id = r.member_id
             WHERE r.status='approved' ORDER BY r.approved_at ASC"
        )->fetchAll();

        $this->render('savings-accounts/fixed-deposit-payout-queue', [
            'pageTitle' => 'Fixed Deposit Payouts — Approved, Awaiting Payment',
            'requests'  => $rows,
        ]);
    }

    public function fixedDepositPayoutForm(): void
    {
        $this->requireFdPayoutAccess();
        $id = (int)($_GET['id'] ?? 0);
        $request = $this->findClosureRequestOrAbort($id);

        $approvedByStmt = $this->db()->prepare("SELECT full_name FROM users WHERE id=?");
        $approvedByStmt->execute([$request['approved_by']]);

        $this->render('savings-accounts/fixed-deposit-payout-form', [
            'pageTitle'  => 'Record Fixed Deposit Payout — Request #' . $id,
            'request'    => $request,
            'approvedBy' => $approvedByStmt->fetchColumn() ?: 'Unknown',
            'csrfToken'  => $this->getCsrf(),
        ]);
    }

    public function fixedDepositPayoutStore(): void
    {
        $this->requireFdPayoutAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-fd-payouts');
            return;
        }
        $id = (int)($_POST['request_id'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
        $paymentReference = trim($_POST['payment_reference'] ?? '') ?: null;
        $paymentDate = trim($_POST['payment_date'] ?? '') ?: date('Y-m-d');

        try {
            $result = (new FixedDepositClosureService())->payout(
                $id, (int)Session::get('user_id'), $paymentMethod, $paymentReference, $paymentDate
            );
            if (!empty($result['already_paid'])) {
                Session::flash('success', "Request #{$id} was already paid — no duplicate payout was created.");
            } else {
                $request = $this->findClosureRequestOrAbort($id);
                (new NotificationModel())->notifyRoles(
                    ['admin', 'office_admin'],
                    "Fixed Deposit paid out and closed",
                    "Fixed Deposit {$request['account_number']} was paid out (Shs " . number_format($result['amount_paid'], 2) . ") and is now closed.",
                    'success', 'fixed_deposit_closure', $id,
                    ['action_url' => APP_URL . '/index.php?page=savings-account-view&id=' . $request['savings_account_id']],
                    "fd_closure_paid:{$id}"
                );
                Session::flash('success', "Payout recorded — Shs " . number_format($result['amount_paid'], 2) .
                    " paid, journal entry {$result['entry_number']}. Fixed Deposit closed.");
            }
            $this->redirect(APP_URL . '/index.php?page=savings-account-fd-payouts');
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=savings-account-fd-payout-form&id=' . $id);
        }
    }

    // ================================================================
    // Stage 11 — UNIVERSAL SAVINGS ACCOUNT CLOSURE
    // (compulsory, voluntary, joint, corporate)
    //
    // Fixed Deposit keeps using the dedicated fixedDeposit* actions above
    // unchanged. Every rule lives in SavingsAccountClosureService -- these
    // actions only authorize, validate input shape, call the service, and
    // redirect, exactly mirroring the FD-2 actions' own shape.
    // ================================================================

    private function findUniversalClosureRequestOrAbort(int $id): array
    {
        $stmt = $this->db()->prepare(
            "SELECT r.*, a.account_number, a.status AS account_status,
                    m.first_name, m.last_name, m.member_number
             FROM `savings_account_closure_requests` r
             JOIN `member_savings_accounts` a ON a.id = r.savings_account_id
             JOIN `members` m ON m.id = r.member_id
             WHERE r.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            die('Closure request not found.');
        }
        return $row;
    }

    // ---- OFFICE ADMIN: request ------------------------------------

    public function closureRequestForm(): void
    {
        $this->requireClosureRequestAccess();
        $id = (int)($_GET['id'] ?? 0);
        $account = $this->accountModel->getAccount($id);
        if (!$account || !in_array($account['account_type'], SavingsAccountClosureService::ACCOUNT_TYPES, true)) {
            http_response_code(404);
            die('Savings account not found.');
        }
        $holders = $this->holderModel->getAccountHolders($id);
        $eligibility = (new SavingsAccountClosureService())->checkEligibility($account);

        $this->render('savings-accounts/closure-request', [
            'pageTitle'   => 'Request Account Closure — ' . $account['account_number'],
            'account'     => $account,
            'holders'     => $holders,
            'eligibility' => $eligibility,
            'csrfToken'   => $this->getCsrf(),
        ]);
    }

    public function closureRequestStore(): void
    {
        $this->requireClosureRequestAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-accounts');
            return;
        }
        $id = (int)($_POST['account_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '') ?: null;
        $holderId = isset($_POST['holder_member_id']) && $_POST['holder_member_id'] !== ''
            ? (int)$_POST['holder_member_id'] : null;
        try {
            $service = new SavingsAccountClosureService();
            $requestId = $service->requestClosure($id, (int)Session::get('user_id'), $reason, $holderId);
            $account = $this->accountModel->getAccount($id);
            (new NotificationModel())->notifyRoles(
                ['admin', 'treasurer'],
                "Savings account closure awaiting approval",
                ucfirst($account['account_type']) . " account {$account['account_number']} closure request #{$requestId} needs your approval.",
                'info', 'savings_closure', $requestId,
                ['action_url' => APP_URL . '/index.php?page=savings-account-closure-review&id=' . $requestId],
                "savings_closure_requested:{$requestId}"
            );
            Session::flash('success', "Closure requested (request #{$requestId}). It now awaits Treasurer approval.");
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $id);
    }

    public function closureCancel(): void
    {
        $this->requireClosureRequestAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-accounts');
            return;
        }
        $requestId = (int)($_POST['request_id'] ?? 0);
        $accountId = (int)($_POST['account_id'] ?? 0);
        try {
            (new SavingsAccountClosureService())->cancel($requestId, (int)Session::get('user_id'));
            Session::flash('success', 'Closure request cancelled.');
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=savings-account-view&id=' . $accountId);
    }

    // ---- TREASURER: review / approve / reject ----------------------

    public function closureQueue(): void
    {
        $this->requireClosureApproveAccess();
        $rows = $this->db()->query(
            "SELECT r.*, a.account_number, m.first_name, m.last_name, m.member_number
             FROM `savings_account_closure_requests` r
             JOIN `member_savings_accounts` a ON a.id = r.savings_account_id
             JOIN `members` m ON m.id = r.member_id
             WHERE r.status='pending' ORDER BY r.requested_at ASC"
        )->fetchAll();

        $this->render('savings-accounts/closure-queue', [
            'pageTitle' => 'Savings Account Closure Requests — Pending Approval',
            'requests'  => $rows,
        ]);
    }

    public function closureReview(): void
    {
        $this->requireClosureApproveAccess();
        $id = (int)($_GET['id'] ?? 0);
        $request = $this->findUniversalClosureRequestOrAbort($id);

        $requestedByStmt = $this->db()->prepare("SELECT full_name FROM users WHERE id=?");
        $requestedByStmt->execute([$request['requested_by']]);

        $this->render('savings-accounts/closure-review', [
            'pageTitle'     => 'Review Closure Request #' . $id,
            'request'       => $request,
            'requestedBy'   => $requestedByStmt->fetchColumn() ?: 'Unknown',
            'currentUserId' => (int)Session::get('user_id'),
            'csrfToken'     => $this->getCsrf(),
        ]);
    }

    public function closureApprove(): void
    {
        $this->requireClosureApproveAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-closures');
            return;
        }
        $id = (int)($_POST['request_id'] ?? 0);
        try {
            (new SavingsAccountClosureService())->approve($id, (int)Session::get('user_id'));
            $request = $this->findUniversalClosureRequestOrAbort($id);
            (new NotificationModel())->notifyRoles(
                ['admin', 'cashier'],
                "Savings account closure ready for settlement",
                ucfirst($request['account_type']) . " account {$request['account_number']} closure request #{$id} was approved and now awaits settlement.",
                'success', 'savings_closure', $id,
                ['action_url' => APP_URL . '/index.php?page=savings-account-settle-form&id=' . $id],
                "savings_closure_approved:{$id}"
            );
            Session::flash('success', "Closure request #{$id} approved. It now awaits Cashier settlement.");
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=savings-account-closures');
    }

    public function closureReject(): void
    {
        $this->requireClosureApproveAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-closures');
            return;
        }
        $id = (int)($_POST['request_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        try {
            (new SavingsAccountClosureService())->reject($id, (int)Session::get('user_id'), $reason);
            $request = $this->findUniversalClosureRequestOrAbort($id);
            (new NotificationModel())->notifyRoles(
                ['admin', 'office_admin'],
                "Savings account closure rejected",
                ucfirst($request['account_type']) . " account {$request['account_number']} closure request #{$id} was rejected: {$reason}",
                'critical', 'savings_closure', $id,
                ['action_url' => APP_URL . '/index.php?page=savings-account-view&id=' . $request['savings_account_id']],
                "savings_closure_rejected:{$id}"
            );
            Session::flash('success', "Closure request #{$id} rejected.");
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect(APP_URL . '/index.php?page=savings-account-closures');
    }

    // ---- CASHIER: settlement ----------------------------------------

    public function closureSettleQueue(): void
    {
        $this->requireClosureSettleAccess();
        $rows = $this->db()->query(
            "SELECT r.*, a.account_number, m.first_name, m.last_name, m.member_number
             FROM `savings_account_closure_requests` r
             JOIN `member_savings_accounts` a ON a.id = r.savings_account_id
             JOIN `members` m ON m.id = r.member_id
             WHERE r.status='approved' ORDER BY r.approved_at ASC"
        )->fetchAll();

        $this->render('savings-accounts/closure-settle-queue', [
            'pageTitle' => 'Savings Account Settlements — Approved, Awaiting Settlement',
            'requests'  => $rows,
        ]);
    }

    public function closureSettleForm(): void
    {
        $this->requireClosureSettleAccess();
        $id = (int)($_GET['id'] ?? 0);
        $request = $this->findUniversalClosureRequestOrAbort($id);

        // Redisplay the CURRENT balance, not the stale request-time
        // snapshot -- settle() itself recalculates fresh again anyway,
        // this is purely so the cashier isn't shown a stale number.
        $currentBalance = $this->accountModel->getAccountBalance((int)$request['savings_account_id']);

        $approvedByStmt = $this->db()->prepare("SELECT full_name FROM users WHERE id=?");
        $approvedByStmt->execute([$request['approved_by']]);

        $this->render('savings-accounts/closure-settle-form', [
            'pageTitle'      => 'Settle Account Closure — Request #' . $id,
            'request'        => $request,
            'currentBalance' => $currentBalance,
            'approvedBy'     => $approvedByStmt->fetchColumn() ?: 'Unknown',
            'csrfToken'      => $this->getCsrf(),
        ]);
    }

    public function closureSettleStore(): void
    {
        $this->requireClosureSettleAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=savings-account-settlements');
            return;
        }
        $id = (int)($_POST['request_id'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? '') ?: null;
        $paymentReference = trim($_POST['payment_reference'] ?? '') ?: null;
        $paymentDate = trim($_POST['payment_date'] ?? '') ?: date('Y-m-d');

        try {
            $result = (new SavingsAccountClosureService())->settle(
                $id, (int)Session::get('user_id'), $paymentMethod, $paymentReference, $paymentDate
            );
            if (!empty($result['already_paid'])) {
                Session::flash('success', "Request #{$id} was already settled — no duplicate settlement was created.");
            } elseif ($result['amount_paid'] > 0) {
                $request = $this->findUniversalClosureRequestOrAbort($id);
                (new NotificationModel())->notifyRoles(
                    ['admin', 'office_admin'],
                    "Savings account settled and closed",
                    ucfirst($request['account_type']) . " account {$request['account_number']} was settled (Shs " . number_format($result['amount_paid'], 2) . ") and is now closed.",
                    'success', 'savings_closure', $id,
                    ['action_url' => APP_URL . '/index.php?page=savings-account-view&id=' . $request['savings_account_id']],
                    "savings_closure_settled:{$id}"
                );
                Session::flash('success', "Settlement recorded — Shs " . number_format($result['amount_paid'], 2) .
                    " paid, journal entry {$result['entry_number']}. Account closed.");
            } else {
                $request = $this->findUniversalClosureRequestOrAbort($id);
                (new NotificationModel())->notifyRoles(
                    ['admin', 'office_admin'],
                    "Savings account closed",
                    ucfirst($request['account_type']) . " account {$request['account_number']} closed — zero balance, no settlement was required.",
                    'success', 'savings_closure', $id,
                    ['action_url' => APP_URL . '/index.php?page=savings-account-view&id=' . $request['savings_account_id']],
                    "savings_closure_settled:{$id}"
                );
                Session::flash('success', "Account closed — zero balance, no settlement was required.");
            }
            $this->redirect(APP_URL . '/index.php?page=savings-account-settlements');
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=savings-account-settle-form&id=' . $id);
        }
    }
}
