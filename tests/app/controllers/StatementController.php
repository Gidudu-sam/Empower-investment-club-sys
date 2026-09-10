<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/StatementModel.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/models/SettingsModel.php';
require_once APP_PATH  . '/models/LoanModel.php';
require_once APP_PATH  . '/models/MemberSavingsAccountModel.php';
require_once APP_PATH  . '/services/MailerService.php';
require_once APP_PATH  . '/services/PdfService.php';

/**
 * StatementController — Member Savings & Shares Statement
 */
class StatementController extends Controller
{
    private StatementModel $model;
    private MemberModel    $memberModel;
    private SettingsModel  $settings;
    private LoanModel      $loanModel;
    private MemberSavingsAccountModel $savingsAccountModel;
    private MailerService  $mailer;
    private PdfService     $pdfService;

    public function __construct()
    {
        // Stage 1 security remediation: every action here can look up ANY
        // member's full financial statement by changing member_id in the
        // URL -- there is no per-member ownership scoping (and no
        // users->member linkage exists to build one against). Restrict the
        // whole controller to staff roles rather than leave it open to any
        // authenticated session; "member" is excluded, matching the brief's
        // "must not access administrative staff workflows" rule.
        // Stage 23: Secretary and Vice Chairman both need statement access
        // per management's explicit governance requirement (records/
        // oversight for Secretary; broader oversight for Vice Chairman).
        // Read-only view access only -- neither role gains emailAll (still
        // admin/system_admin/office_admin, unchanged below).
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'treasurer', 'cashier', 'viewer', 'chairman', 'office_admin', 'secretary', 'vice_chairman'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view member statements.');
        }

        $this->model       = new StatementModel();
        $this->memberModel = new MemberModel();
        $this->settings    = new SettingsModel();
        $this->loanModel   = new LoanModel();
        $this->savingsAccountModel = new MemberSavingsAccountModel();
        $this->mailer              = new MailerService();
        $this->pdfService          = new PdfService();
    }

    public function index(): void
    {
        Session::requireAuth();
        $this->render('statements/index', [
            'pageTitle'   => 'Member Statements — ' . APP_NAME,
            'breadcrumbs' => [['label' => 'Statements']],
            'years'       => $this->model->allFinancialYears(),
            'currentYear' => StatementModel::dateToFY(new DateTime()),
            'success'     => Session::flash('success'),
            'error'       => Session::flash('error'),
            'canEmailAll' => Session::hasRole(['admin', 'system_admin', 'office_admin']),
            'csrfToken'   => $this->getCsrf(),
        ], 'main');
    }

    /**
     * Bulk-emails every active member their statement for one chosen
     * period (Stage: Member Statement Emailing) -- the "at least every
     * month" ask, run manually by staff for now rather than on an
     * unattended schedule (this app has no cron/task-scheduler wired up
     * yet; see MailerService/app/config/mail.php). Reuses the exact same
     * StatementModel::resolvePeriod()/buildMemberStatement() pipeline and
     * statements/email.php template as the member portal's own
     * "Email Me a Copy" -- one statement engine, three trigger surfaces
     * (staff single-member print, member portal, this bulk action).
     *
     * Narrower than the controller's general view-access gate: sending
     * external communication to every member at once is a materially
     * different risk than looking up one member's own statement, so this
     * is restricted to admin/system_admin plus office_admin specifically
     * -- office_admin already owns member-facing communication in this
     * app (the member follow-up/collections workspace on the dashboard),
     * so routine statement broadcasts fit that same role rather than
     * requiring a System Administrator every month. treasurer/cashier/
     * viewer/chairman keep view-only statement access, unchanged.
     */
    public function emailAll(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'system_admin', 'office_admin'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to send bulk statement emails.');
        }
        if (!$this->isPost() || !$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=statements');
            return;
        }

        $statementType = in_array($_POST['statement_type'] ?? '', ['savings', 'shares'], true) ? $_POST['statement_type'] : 'savings';
        [$startDate, $endDate, $periodLabel, $fyYear] = StatementModel::resolvePeriod($_POST);
        $shareValue = (float)$this->settings->get('share_value', '20000');

        // 13E-MAIL-01 idempotency guard: a resubmit for the exact same
        // type+period (double-click past the JS confirm(), browser
        // back+resubmit, or a deliberate retry) would otherwise re-email
        // every member who already received a statement -- the precise
        // failure category behind a real incident this app has already
        // had. Keyed on the resolved date range rather than the raw
        // request params, so a "Last 1 Month" preset computed on two
        // different days that happens to land on the same window still
        // collides correctly. force_resend=1 (an explicit checkbox, not
        // a default) is the only way to bypass this within the window.
        $logKey = "KEY:{$statementType}|{$startDate}|{$endDate}";
        if (($_POST['force_resend'] ?? '') !== '1') {
            $recent = $this->settings->recentLog('bulk_statement_email', $logKey, 30);
            if ($recent) {
                $when = date('g:i A', strtotime($recent['created_at']));
                Session::flash('error', "A {$statementType} statement broadcast for {$periodLabel} was already sent at {$when} (within the last 30 minutes). Check Settings \u{2192} Audit Logs for the outcome, or tick \"Resend anyway\" if you really mean to send it again.");
                $this->redirect(APP_URL . '/index.php?page=statements');
                return;
            }
        }

        // Active members only -- the same population the dashboard's own
        // "Active Members" figure counts; inactive/dormant accounts are
        // deliberately excluded from a routine statement broadcast.
        $members = $this->memberModel->search('', 'active', '', '', 1, 100000)['rows'];

        $logo = [['path' => PUBLIC_PATH . '/images/logo-email.png', 'cid' => 'logo']];
        $sent = 0; $skipped = 0; $failed = 0;
        foreach ($members as $member) {
            if (empty($member['email'])) { $skipped++; continue; }

            // Every step for this one member is inside the try -- PDF
            // generation (Dompdf) can throw for reasons that have nothing
            // to do with SMTP (a missing PHP extension, a malformed
            // image), and unlike MailerService::send() itself (which only
            // ever returns a result array), nothing else here catches
            // that. Confirmed the hard way in single-member testing: an
            // uncaught PDF error surfaced as a raw fatal-error page
            // instead of a graceful failure -- in this loop, over many
            // members, that would have aborted the entire batch after
            // whichever member happened to fail first. \Throwable, not
            // just PHPMailer's exception type, since the failure modes
            // here are genuinely unpredictable third-party library errors.
            try {
                $data = $this->model->buildMemberStatement($member, $startDate, $endDate, $periodLabel, $fyYear, $shareValue, $this->loanModel);
                $data['statementType'] = $statementType;

                $fullName   = $member['first_name'] . ' ' . $member['last_name'];
                $html       = $this->renderToString('statements/email', $data);
                $pdfBytes   = $this->pdfService->renderHtml($this->renderToString('statements/pdf', $data));
                $subject    = APP_NAME . ' — Your ' . ucfirst($statementType) . ' Statement (' . $periodLabel . ')';
                $pdfName    = $fullName . ' — ' . ucfirst($statementType) . ' Statement.pdf';
                $attachment = [['content' => $pdfBytes, 'filename' => $pdfName]];

                $result = $this->mailer->send($member['email'], $fullName, $subject, $html, $logo, $attachment);
                if ($result['success']) { $sent++; } else { $failed++; }
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        // Permanent record of exactly what a bulk send did -- there was no
        // audit trail here before, which meant that when one accidental
        // test run took long enough for its own result flash to be raced
        // and lost (flash messages are read-once), there was no way to
        // afterward confirm how many real emails actually went out. This
        // exists so that question is always answerable from Settings ->
        // Audit Logs, independent of whether anyone saw the on-screen result.
        $this->settings->log(
            (int)Session::get('user_id'),
            'bulk_statement_email',
            "{$logKey} {$statementType} statement, {$periodLabel}: {$sent} sent, {$skipped} skipped, {$failed} failed"
        );

        if ($sent === 0 && $failed === 0 && $skipped > 0) {
            Session::flash('error', "No statements were sent -- all {$skipped} active member(s) have no email on file.");
        } elseif ($failed > 0 && $sent === 0) {
            Session::flash('error', "Could not send any statements ({$failed} failed). Check that email sending is configured (app/config/mail.php).");
        } else {
            Session::flash('success', "Statements for {$periodLabel}: {$sent} sent, {$skipped} skipped (no email on file), {$failed} failed.");
        }
        $this->redirect(APP_URL . '/index.php?page=statements');
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

    public function view(): void
    {
        Session::requireAuth();
        $memberId = (int)($_GET['member_id'] ?? 0);
        $member   = $this->memberModel->find($memberId);
        if (!$member) {
            Session::flash('error', 'Member not found.');
            $this->redirect(APP_URL . '/index.php?page=statements');
            return;
        }
        $statementType = in_array($_GET['statement_type'] ?? '', ['savings', 'shares'], true) ? $_GET['statement_type'] : 'savings';
        [$startDate, $endDate, $periodLabel, $fyYear] = $this->resolvePeriod($_GET);
        $data = $this->buildStatementData($member, $startDate, $endDate, $periodLabel, $fyYear);
        $this->render('statements/view', array_merge($data, [
            'pageTitle'     => 'Statement — ' . $member['first_name'] . ' ' . $member['last_name'],
            'statementType' => $statementType,
            'breadcrumbs' => [
                ['label' => 'Statements', 'url' => APP_URL . '/index.php?page=statements'],
                ['label' => $member['first_name'] . ' ' . $member['last_name']],
            ],
            'years' => $this->model->availableYears($memberId),
        ]), 'main');
    }

    public function printView(): void
    {
        Session::requireAuth();
        $memberId = (int)($_GET['member_id'] ?? 0);
        $member   = $this->memberModel->find($memberId);
        if (!$member) { $this->redirect(APP_URL . '/index.php?page=statements'); return; }
        $statementType = in_array($_GET['statement_type'] ?? '', ['savings', 'shares'], true) ? $_GET['statement_type'] : 'savings';
        [$startDate, $endDate, $periodLabel, $fyYear] = $this->resolvePeriod($_GET);
        $data = $this->buildStatementData($member, $startDate, $endDate, $periodLabel, $fyYear);
        $data['statementType'] = $statementType;
        $this->render('statements/print', $data, null);
    }

    /**
     * Resolve the requested statement period: either a financial year
     * (existing behavior, default) or an explicit custom date range
     * (?range_mode=custom&date_from=...&date_to=...) -- e.g. "last 3
     * months" or "1 week" ranges a member/staff may ask for, which don't
     * align to the May-April financial year.
     * Returns [startDate, endDate, periodLabel, fyYearForSharePosition].
     * Share position is always computed as of the FY containing the
     * period's end date -- share capital is a point-in-time accumulation,
     * not something a custom transaction window changes the meaning of.
     */
    private function resolvePeriod(array $get): array
    {
        return StatementModel::resolvePeriod($get);
    }

    public function search(): void
    {
        Session::requireAuth();
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { $this->json(['members' => []]); return; }
        $result = $this->memberModel->search($q, '', '', '', 1, 10);
        $out = array_map(fn($m) => [
            'id'            => (int)$m['id'],
            'member_number' => $m['member_number'],
            'full_name'     => $m['first_name'] . ' ' . $m['last_name'],
            'phone'         => $m['phone'],
        ], $result['rows']);
        $this->json(['members' => $out]);
    }

    /**
     * AJAX — a concise WhatsApp-shareable text summary of a member's
     * savings/shares statement (opening/closing balance, credits/debits,
     * share position), mirroring LoanController::whatsappSchedule()'s
     * existing wa.me pattern exactly -- same mechanism, no new API/account.
     * Deliberately a summary, not the full transaction list: a statement
     * can run to dozens of rows, which both risks exceeding what wa.me
     * can carry in a URL and is more than a WhatsApp message should
     * reasonably contain.
     */
    public function whatsappSummary(): void
    {
        Session::requireAuth();
        $memberId = (int)($_GET['member_id'] ?? 0);
        $member   = $this->memberModel->find($memberId);
        if (!$member) { $this->json(['error' => 'Member not found']); return; }

        [$startDate, $endDate, $periodLabel, $fyYear] = $this->resolvePeriod($_GET);
        $data = $this->buildStatementData($member, $startDate, $endDate, $periodLabel, $fyYear);
        $text = $member['first_name'] . ' ' . $member['last_name'] . " ({$member['member_number']})\n";
        $text .= "Savings & Shares Statement — {$data['fyLabel']}\n\n";
        $text .= "Opening Balance: Shs " . number_format($data['openingBalance'], 0) . "\n";
        $text .= "Total Deposits: Shs " . number_format($data['totalCredits'], 0) . "\n";
        $text .= "Total Withdrawals: Shs " . number_format($data['totalDebits'], 0) . "\n";
        $text .= "Closing Savings Balance: Shs " . number_format($data['closingBalance'], 0) . "\n";
        $text .= "Share Capital: Shs " . number_format($data['shareCapital'], 0) . " ({$data['shareCount']} shares)\n";
        $text .= "Total Member Assets: Shs " . number_format($data['totalMemberAssets'], 0) . "\n";
        if ($data['loanCount'] > 0) {
            $text .= "\nLoan Status: {$data['loanStatus']}\n";
            $text .= "Outstanding Loan Balance: Shs " . number_format($data['outstandingBal'], 0) . "\n";
        }
        $text .= "\nAs of " . date('d F Y');

        $this->json(['text' => $text, 'url' => 'https://wa.me/?text=' . urlencode($text)]);
    }

    public function memberLoansAjax(): void
    {
        Session::requireAuth();
        $memberId = (int)($_GET['member_id'] ?? 0);
        if ($memberId < 1) { $this->json(['loans' => []]); return; }

        $loans = $this->loanModel->memberLoanHistory($memberId, 20);
        $out = array_map(fn($l) => [
            'id'          => (int)$l['id'],
            'loan_number' => $l['loan_number'],
            'loan_amount' => (float)$l['loan_amount'],
            'status'      => ucfirst($l['status']),
            'issue_date'  => $l['issue_date'],
        ], $loans);

        $this->json(['loans' => $out]);
    }

    /** AJAX: this member's individual savings accounts (Compulsory,
     *  Voluntary, Joint, Corporate, Fixed Monthly), each with its own
     *  balance -- powers the "Savings Account Statement" picker on this
     *  page, mirroring memberLoansAjax()'s existing pattern exactly.
     *  Reuses MemberSavingsAccountModel untouched; generating the actual
     *  statement is delegated entirely to the already-built, already
     *  account_type-agnostic SavingsAccountController::statement() --
     *  no second per-account statement engine is created here. */
    public function memberSavingsAccountsAjax(): void
    {
        Session::requireAuth();
        $memberId = (int)($_GET['member_id'] ?? 0);
        if ($memberId < 1) { $this->json(['accounts' => []]); return; }

        $typeLabels = ['compulsory' => 'Compulsory', 'voluntary' => 'Voluntary', 'joint' => 'Joint', 'corporate' => 'Corporate', 'fixed_deposit' => 'Fixed Deposit'];
        $accounts = $this->savingsAccountModel->getMemberAccounts($memberId);
        $out = array_map(fn($a) => [
            'id'             => (int)$a['id'],
            'account_number' => $a['account_number'],
            'account_type'   => $typeLabels[$a['account_type']] ?? ucfirst($a['account_type']),
            'account_type_key' => $a['account_type'],
            'balance'        => $this->savingsAccountModel->getAccountBalance((int)$a['id']),
            'status'         => ucfirst($a['status']),
        ], $accounts);

        $this->json(['accounts' => $out]);
    }

    /**
     * Thin wrapper -- the actual calculation now lives in
     * StatementModel::buildMemberStatement() (Stage: member statement
     * download) so the member portal's own print action can reuse the
     * exact same logic instead of a second copy. Behavior/signature here
     * are unchanged for every existing caller (view(), printView(),
     * whatsappSummary()).
     */
    private function buildStatementData(array $member, string $startDate, string $endDate, string $periodLabel, int $fyYearForShares): array
    {
        $shareValue = (float)$this->settings->get('share_value', '20000');
        return $this->model->buildMemberStatement($member, $startDate, $endDate, $periodLabel, $fyYearForShares, $shareValue, $this->loanModel);
    }
}
