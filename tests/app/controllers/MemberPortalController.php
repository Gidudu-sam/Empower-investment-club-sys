<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/models/SavingsModel.php';
require_once APP_PATH  . '/models/MemberSavingsAccountModel.php';
require_once APP_PATH  . '/models/LoanModel.php';
require_once APP_PATH  . '/models/RepaymentModel.php';
require_once APP_PATH  . '/models/FeeModel.php';
require_once APP_PATH  . '/models/UserModel.php';
require_once APP_PATH  . '/models/StatementModel.php';
require_once APP_PATH  . '/models/SettingsModel.php';
require_once APP_PATH  . '/services/MailerService.php';
require_once APP_PATH  . '/services/PdfService.php';

/**
 * MemberPortalController — Stage 14-B.
 *
 * The one and only rule every method in this class follows:
 *
 *     $memberId = Session::requireMember();
 *
 * never $_GET['member_id'], $_POST['member_id'], or any other
 * browser-supplied value. That single line is this controller's entire
 * authorization boundary -- it authenticates, confirms the role is
 * 'member', confirms a real member_id is linked, and (via
 * Session::revalidateAuthorization(), already running inside
 * Session::requireAuth() since Stage 13-C) guarantees the id reflects
 * whatever the database says right now, not whatever was true at login.
 *
 * Every model call below reuses an existing, already-audited method --
 * nothing here recalculates a balance, posts a transaction, or
 * duplicates any accounting logic. This controller only reads.
 */
class MemberPortalController extends Controller
{
    private MemberModel $memberModel;
    private SavingsModel $savingsModel;
    private MemberSavingsAccountModel $accountModel;
    private LoanModel $loanModel;
    private RepaymentModel $repaymentModel;
    private FeeModel $feeModel;
    private UserModel $userModel;
    private StatementModel $statementModel;
    private SettingsModel $settingsModel;
    private MailerService $mailerService;
    private PdfService $pdfService;

    public function __construct()
    {
        $this->memberModel    = new MemberModel();
        $this->savingsModel   = new SavingsModel();
        $this->accountModel   = new MemberSavingsAccountModel();
        $this->loanModel      = new LoanModel();
        $this->repaymentModel = new RepaymentModel();
        $this->feeModel       = new FeeModel();
        $this->userModel      = new UserModel();
        $this->statementModel = new StatementModel();
        $this->settingsModel  = new SettingsModel();
        $this->mailerService  = new MailerService();
        $this->pdfService     = new PdfService();
    }

    // ----------------------------------------------------------------
    // HOME — quick summary, links into the detail pages below
    // ----------------------------------------------------------------
    public function home(): void
    {
        $memberId = Session::requireMember();
        $member   = $this->memberModel->find($memberId);

        // Shares: reuses StatementModel::sharePosition(), the exact same
        // already-audited calculation StatementController uses for staff
        // (retained_amount from `withdrawals`, up to the current FY, at
        // the configured share_value) -- no second share-capital engine,
        // just the same read exposed through the member's own safe path.
        $shareValue = (float)$this->settingsModel->get('share_value', '20000');
        $currentFY  = StatementModel::dateToFY(new DateTime());

        $this->render('portal/home', [
            'pageTitle'      => 'My Account — ' . APP_NAME,
            'member'         => $member,
            'savingsBalance' => $this->savingsModel->memberBalance($memberId),
            'activeLoan'     => $this->loanModel->memberActiveLoan($memberId),
            'pendingFees'    => array_values(array_filter($this->feeModel->getMemberFees($memberId), fn($f) => $f['status'] === 'pending')),
            'sharePosition'  => $this->statementModel->sharePosition($memberId, $currentFY, $shareValue),
            'savingsTrend'   => $this->savingsModel->memberMonthlyTrend($memberId, 6),
            'recentTxns'     => $this->savingsModel->memberHistory($memberId, 5),
            'success'        => Session::flash('success'),
            'error'          => Session::flash('error'),
        ], 'portal');
    }

    // ----------------------------------------------------------------
    // PROFILE — read-only. Editing a member's own record is still a
    // staff (office_admin) action; the portal does not duplicate it.
    // ----------------------------------------------------------------
    public function profile(): void
    {
        $memberId = Session::requireMember();
        $member   = $this->memberModel->find($memberId);

        $this->render('portal/profile', [
            'pageTitle' => 'My Profile — ' . APP_NAME,
            'member'    => $member,
        ], 'portal');
    }

    // ----------------------------------------------------------------
    // SAVINGS — accounts + full history. No id is ever accepted from the
    // browser here; both are derived entirely from the authenticated
    // member, so there is no parameter for an IDOR attempt to manipulate.
    // memberHistory() already queries by member_id (not
    // savings_account_id), which is why it correctly includes both the
    // 44 legacy pre-Stage-4 rows and the newer account-linked rows alike
    // -- confirmed in the Stage 14 audit, unchanged here.
    // ----------------------------------------------------------------
    public function savings(): void
    {
        $memberId = Session::requireMember();
        $member   = $this->memberModel->find($memberId);

        $this->render('portal/savings', [
            'pageTitle'    => 'My Savings — ' . APP_NAME,
            'member'       => $member,
            'accounts'     => $this->accountModel->getMemberAccounts($memberId),
            'summary'      => $this->accountModel->getMemberSavingsSummary($memberId),
            'balance'      => $this->savingsModel->memberBalance($memberId),
            'depositCount' => $this->savingsModel->memberDepositCount($memberId),
            'history'      => $this->savingsModel->memberHistory($memberId, 100),
        ], 'portal');
    }

    // ----------------------------------------------------------------
    // LOANS — list + active loan summary. Drilling into one specific
    // loan is a separate action (loanView) with its own ownership-
    // enforced query, per the brief's explicit preference for enforcing
    // ownership inside the query rather than fetch-then-check.
    // ----------------------------------------------------------------
    public function loans(): void
    {
        $memberId = Session::requireMember();
        $member   = $this->memberModel->find($memberId);

        $this->render('portal/loans', [
            'pageTitle'   => 'My Loans — ' . APP_NAME,
            'member'      => $member,
            'activeLoan'  => $this->loanModel->memberActiveLoan($memberId),
            'history'     => $this->loanModel->memberLoanHistory($memberId, 100),
            'summary'     => $this->loanModel->memberLoanSummary($memberId),
        ], 'portal');
    }

    /**
     * A single loan's detail (schedule, repayment history). Ownership is
     * enforced by LoanModel::findWithDetailsForMember()'s own
     * "AND member_id = ?" clause -- a loan id that exists but belongs to
     * another member returns exactly the same "not found" as an id that
     * doesn't exist at all, so this never discloses whether another
     * member's loan exists.
     */
    public function loanView(): void
    {
        $memberId = Session::requireMember();
        $loanId   = (int)($_GET['id'] ?? 0);

        $loan = $this->loanModel->findWithDetailsForMember($loanId, $memberId);
        if (!$loan) {
            http_response_code(404);
            $this->render('errors/404', [], null);
            return;
        }

        $this->render('portal/loan-view', [
            'pageTitle'    => 'Loan ' . $loan['loan_number'] . ' — ' . APP_NAME,
            'loan'         => $loan,
            'installments' => $this->loanModel->getInstallments($loanId),
            'repayments'   => array_reverse($this->repaymentModel->forLoan($loanId)),
        ], 'portal');
    }

    // ----------------------------------------------------------------
    // REPAYMENTS — every repayment across every one of the member's own
    // loans. RepaymentModel::search() already accepts a member_id filter
    // (used today by staff-facing screens); reused unchanged, scoped to
    // the authenticated member instead of a staff-selected one.
    // ----------------------------------------------------------------
    public function repayments(): void
    {
        $memberId = Session::requireMember();
        $member   = $this->memberModel->find($memberId);
        // RepaymentModel::search() already orders most-recent-first
        // (r.payment_date DESC, r.id DESC) -- used as-is, not reversed.
        $result   = $this->repaymentModel->search('', '', '', '', 0, $memberId, 1, 200);

        $this->render('portal/repayments', [
            'pageTitle'   => 'My Repayments — ' . APP_NAME,
            'member'      => $member,
            'repayments'  => $result['rows'],
            'totalPaid'   => $result['totalPaid'],
        ], 'portal');
    }

    // ----------------------------------------------------------------
    // FEES
    // ----------------------------------------------------------------
    public function fees(): void
    {
        $memberId = Session::requireMember();
        $member   = $this->memberModel->find($memberId);

        $this->render('portal/fees', [
            'pageTitle' => 'My Fees — ' . APP_NAME,
            'member'    => $member,
            'fees'      => $this->feeModel->getMemberFees($memberId),
        ], 'portal');
    }

    // ----------------------------------------------------------------
    // STATEMENT — a member-only rendering, deliberately NOT a refactor
    // of StatementController (which stays exactly as-is for staff, who
    // must keep being able to select which member's statement to view).
    // Built from the same reused model methods as the savings/loans
    // pages above, filtered to a financial year, so there is no second
    // balance/statement engine -- only a second, narrower authorization
    // path onto the same underlying data.
    // ----------------------------------------------------------------
    public function statement(): void
    {
        $memberId = Session::requireMember();
        $member   = $this->memberModel->find($memberId);

        $this->render('portal/statement', [
            'pageTitle'  => 'My Statement — ' . APP_NAME,
            'member'     => $member,
            'savings'    => $this->savingsModel->memberHistory($memberId, 500),
            'loans'      => $this->loanModel->memberLoanHistory($memberId, 100),
            'repayments' => $this->repaymentModel->search('', '', '', '', 0, $memberId, 1, 500)['rows'],
            'fees'       => $this->feeModel->getMemberFees($memberId),
            'years'      => $this->statementModel->availableYears($memberId),
            'currentFY'  => StatementModel::dateToFY(new DateTime()),
            'csrfToken'  => $this->getCsrf(),
            'success'    => Session::flash('success'),
            'error'      => Session::flash('error'),
        ], 'portal');
    }

    // ----------------------------------------------------------------
    // STATEMENT DOWNLOAD/PRINT — the same formal, printable Savings/Shares
    // statement document StatementController already generates for staff
    // (statements/print view), reusing the exact same underlying
    // calculation (StatementModel::buildMemberStatement(), relocated out
    // of StatementController so both share it verbatim -- no second
    // statement engine). The member picks a financial year and a
    // savings/shares view; the browser's own print dialog ("Save as PDF")
    // is the download mechanism, matching every other printable document
    // already in this app (no PDF library exists here, by design).
    // ----------------------------------------------------------------
    public function statementPrint(): void
    {
        $memberId = Session::requireMember();
        $member   = $this->memberModel->find($memberId);

        $statementType = in_array($_GET['statement_type'] ?? '', ['savings', 'shares'], true) ? $_GET['statement_type'] : 'savings';
        // Same period resolution staff already use (StatementModel::
        // resolvePeriod()) -- a financial year by default, or an explicit
        // ?range_mode=custom&date_from=...&date_to=... window (the portal's
        // week/month/3-month/6-month presets are just client-side-computed
        // custom ranges built from this same parameter pair).
        [$startDate, $endDate, $periodLabel, $fyYear] = StatementModel::resolvePeriod($_GET);
        $shareValue = (float)$this->settingsModel->get('share_value', '20000');

        $data = $this->statementModel->buildMemberStatement($member, $startDate, $endDate, $periodLabel, $fyYear, $shareValue, $this->loanModel);
        $data['statementType'] = $statementType;
        $data['portalMode']    = true;

        $this->render('statements/print', $data, null);
    }

    // ----------------------------------------------------------------
    // EMAIL ME A COPY — sends the same statement (same period-resolution,
    // same buildMemberStatement() data) to the member's own on-file email
    // address only. The address always comes from members.email, never
    // from the request, for the same reason member_id never does: a
    // browser-supplied destination would let a member (or a stolen
    // session) redirect their own financial statement to an address that
    // isn't actually theirs on record.
    // ----------------------------------------------------------------
    public function emailStatement(): void
    {
        $memberId = Session::requireMember();
        $member   = $this->memberModel->find($memberId);

        if (!$this->isPost() || !$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=portal-statement');
            return;
        }

        if (empty($member['email'])) {
            Session::flash('error', 'No email address is on file for your account. Please contact the office to add one.');
            $this->redirect(APP_URL . '/index.php?page=portal-statement');
            return;
        }

        $statementType = in_array($_POST['statement_type'] ?? '', ['savings', 'shares'], true) ? $_POST['statement_type'] : 'savings';
        [$startDate, $endDate, $periodLabel, $fyYear] = StatementModel::resolvePeriod($_POST);
        $shareValue = (float)$this->settingsModel->get('share_value', '20000');

        $data = $this->statementModel->buildMemberStatement($member, $startDate, $endDate, $periodLabel, $fyYear, $shareValue, $this->loanModel);
        $data['statementType'] = $statementType;
        $fullName = $member['first_name'] . ' ' . $member['last_name'];

        // PDF generation (Dompdf) can throw for reasons that have nothing
        // to do with SMTP -- a missing PHP extension, a malformed image --
        // and unlike MailerService::send() (which only ever returns a
        // result array, never throws), nothing upstream of this call
        // catches that. Confirmed the hard way: a GD-extension error here
        // surfaced as a raw stack trace to the browser before this
        // try/catch existed. Caught broadly (\Throwable, not just
        // PHPMailer's exception type) since the failure modes here are
        // genuinely unpredictable third-party library errors.
        try {
            $html       = $this->renderToString('statements/email', $data);
            $pdfBytes   = $this->pdfService->renderHtml($this->renderToString('statements/pdf', $data));
            $subject    = APP_NAME . ' — Your ' . ucfirst($statementType) . ' Statement (' . $periodLabel . ')';
            $logo       = [['path' => PUBLIC_PATH . '/images/logo-email.png', 'cid' => 'logo']];
            $pdfName    = $fullName . ' — ' . ucfirst($statementType) . ' Statement.pdf';
            $attachment = [['content' => $pdfBytes, 'filename' => $pdfName]];
            $result     = $this->mailerService->send($member['email'], $fullName, $subject, $html, $logo, $attachment);
        } catch (\Throwable $e) {
            $result = ['success' => false, 'error' => 'Could not generate the statement PDF. Please try again or contact the office.'];
        }

        if ($result['success']) {
            Session::flash('success', 'Your statement has been emailed to ' . $member['email'] . '.');
        } else {
            Session::flash('error', 'Could not send the statement: ' . $result['error']);
        }
        $this->redirect(APP_URL . '/index.php?page=portal-statement');
    }

    // ----------------------------------------------------------------
    // FORCED PASSWORD CHANGE
    //
    // The one portal action Session::requireMember() itself redirects to
    // when force_password_change is set -- this method is the sole
    // caller that passes $allowPendingPasswordChange = true, so it is
    // reachable even while the flag is still set (every other portal
    // method is not, which is what makes the requirement unavoidable by
    // navigating straight to another portal route).
    // ----------------------------------------------------------------
    public function changePassword(): void
    {
        $memberId = Session::requireMember(true);

        if ($this->isPost()) {
            $this->handleChangePassword($memberId);
            return;
        }

        $this->render('portal/change-password', [
            'pageTitle' => 'Change Password — ' . APP_NAME,
            'csrfToken' => $this->getCsrf(),
            'error'     => Session::flash('error'),
        ], 'portal');
    }

    private function handleChangePassword(int $memberId): void
    {
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=portal-change-password');
            return;
        }

        $userId      = (int)Session::get('user_id');
        $current     = $_POST['current_password'] ?? '';
        $new         = $_POST['new_password'] ?? '';
        $confirm     = $_POST['confirm_password'] ?? '';

        $user = $this->userModel->find($userId);
        if (!$user || !$this->userModel->verifyPassword($current, $user['password_hash'])) {
            Session::flash('error', 'Your current password is incorrect.');
            $this->redirect(APP_URL . '/index.php?page=portal-change-password');
            return;
        }
        if (strlen($new) < 6) {
            Session::flash('error', 'New password must be at least 6 characters.');
            $this->redirect(APP_URL . '/index.php?page=portal-change-password');
            return;
        }
        if ($new !== $confirm) {
            Session::flash('error', 'New password and confirmation do not match.');
            $this->redirect(APP_URL . '/index.php?page=portal-change-password');
            return;
        }

        $this->userModel->updatePasswordAndClearForceChange($userId, password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]));
        $this->userModel->logActivity($userId, 'member_password_changed', 'Member changed their own password (forced first-login change).');

        // Session::revalidateAuthorization() will also pick this up on the
        // next request regardless, but clearing it immediately means this
        // same request's subsequent redirect already lands on a fully
        // unlocked portal instead of waiting one more round trip.
        Session::set('force_password_change', false);

        Session::flash('success', 'Password changed successfully.');
        $this->redirect(APP_URL . '/index.php?page=portal-home');
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
}
