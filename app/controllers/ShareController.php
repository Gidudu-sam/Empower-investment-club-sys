<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/ShareModel.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/models/SettingsModel.php';

/**
 * ShareController — Shares Module (Stages 1, 3 & 4-C).
 *
 * Stage 1: read-only Overview/Member Position. Stage 3: historical/opening
 * entry (admin/treasurer only, never journal-posted). Stage 4-C: CURRENT
 * share transaction recording (admin/treasurer/cashier/office_admin,
 * journal-posted) -- a staff-recorded record of a real-world contribution
 * that already occurred and was confirmed outside Empower; this is never a
 * payment-collection mechanism (see ShareModel::createCurrentTransaction()'s
 * docblock). Redemption/transfer/correction remain later, separately-
 * approved stages.
 *
 * Access mirrors the existing `report-shares` viewer set EXACTLY as
 * verified live in ReportController: requireFinancialReportAccess()'s role
 * list (admin, treasurer, cashier, viewer, chairman, loans_officer,
 * secretary, vice_chairman) is then narrowed by blockLoansOfficer(), which
 * actually excludes loans_officer from Member/Savings/Share/Withdrawal/
 * Financial-Summary reports (loans_officer is confined to Loan Report /
 * Loan Aging / Loan Repayment Report only). The real, effective set was
 * therefore: admin, treasurer, cashier, viewer, chairman, secretary,
 * vice_chairman. Stage 4-C adds `office_admin` to THIS view gate --
 * closing the Stage 4-B audit's own documented gap (office_admin was being
 * granted current-transaction WRITE access below without any way to see
 * what it recorded) -- narrowly, for Shares only, not any other module.
 * system_admin remains deliberately excluded from both view and write
 * access -- per approved policy, System Administrator must not gain
 * financial transaction/viewing authority merely from administering the
 * application; system_admin has no presence in any withdrawal-processing
 * or report-shares gate today either.
 */
class ShareController extends Controller
{
    private ShareModel    $model;
    private MemberModel   $memberModel;
    private SettingsModel $settings;

    public function __construct()
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'treasurer', 'cashier', 'viewer', 'chairman', 'secretary', 'vice_chairman', 'office_admin'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view Shares.');
        }

        $this->model       = new ShareModel();
        $this->memberModel = new MemberModel();
        $this->settings    = new SettingsModel();
    }

    private function currentShareValue(): float
    {
        return (float)$this->settings->get('share_value', '20000');
    }

    /**
     * Stage 3 (Historical/Opening Share Entry) write gate -- deliberately
     * narrower than the view gate above. Mirrors MemberAccountAdjustment
     * Controller::requireWriteAccess() and OpeningBalanceController's own
     * create/submit gate exactly (admin, treasurer): this is a one-time,
     * system-setup-style historical entry, not a routine daily transaction,
     * so cashier is intentionally excluded (unlike routine withdrawal
     * processing). chairman/vice_chairman keep VIEW access only here,
     * consistent with how they hold approval-tier (not raw entry-tier)
     * authority on the closest existing analog. system_admin is
     * deliberately excluded -- per approved policy, administering the
     * application must never itself confer financial write authority.
     */
    private function requireWriteAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer'])) {
            http_response_code(403);
            die('Access denied. Admin or Treasurer privileges required to record historical share entries.');
        }
    }

    /**
     * Stage 4-C current-transaction write gate -- deliberately BROADER
     * than Stage 3's requireWriteAccess() (admin/treasurer only), because
     * recording a confirmed current contribution is a ROUTINE, ongoing,
     * day-to-day event -- the same class of event as a savings deposit --
     * not a one-time setup task. Mirrors SavingsAccountController::
     * requireDepositAccess() exactly (admin, treasurer, cashier,
     * office_admin), per the Stage 4/4-B audits' evidence-based
     * recommendation. loans_officer/chairman/vice_chairman/system_admin
     * remain excluded -- no evidence anywhere connects loans staff to
     * shares, and chairman/vice_chairman hold approval/view-tier (not raw
     * entry-tier) authority on every comparable existing workflow.
     */
    private function requireCurrentTransactionAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer', 'cashier', 'office_admin'])) {
            http_response_code(403);
            die('Access denied. Admin, Treasurer, Cashier, or Office Administrator privileges required to record a share transaction.');
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
        return hash_equals($stored, $token);
    }

    // ----------------------------------------------------------------
    // OVERVIEW
    // ----------------------------------------------------------------
    public function index(): void
    {
        Session::requireAuth();

        $shareValue   = $this->currentShareValue();
        $totalQty     = $this->model->totalIssuedQuantity($shareValue);
        $totalCapital = $this->model->totalShareCapital();
        $shareholders = $this->model->shareholderCount();
        $top          = $this->model->topShareholders($shareValue, 20);

        foreach ($top as &$row) {
            $row['ownership_pct'] = ShareModel::ownershipPercentage((float)$row['total_quantity'], $totalQty);
        }
        unset($row);

        $this->render('shares/index', [
            'pageTitle'    => 'Shares — ' . APP_NAME,
            'breadcrumbs'  => [['label' => 'Shares']],
            'shareValue'   => $shareValue,
            'totalQty'     => $totalQty,
            'totalCapital' => $totalCapital,
            'shareholders' => $shareholders,
            'topShareholders' => $top,
            'canRecordHistorical' => Session::hasRole(['admin', 'treasurer']),
            'canRecordTransaction' => Session::hasRole(['admin', 'treasurer', 'cashier', 'office_admin']),
            'csrfToken'    => $this->getCsrf(),
        ]);
    }

    // ----------------------------------------------------------------
    // MEMBER SEARCH (AJAX) — mirrors StatementController::search() exactly
    // ----------------------------------------------------------------
    public function memberSearch(): void
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

    // ----------------------------------------------------------------
    // MEMBER SHARE POSITION + LEDGER
    // ----------------------------------------------------------------
    public function memberPosition(): void
    {
        Session::requireAuth();

        $memberId = (int)($_GET['member_id'] ?? 0);
        $member   = $memberId > 0 ? $this->memberModel->find($memberId) : false;

        $shareValue   = $this->currentShareValue();
        $totalQty     = $this->model->totalIssuedQuantity($shareValue);

        $memberQty     = 0.0;
        $memberCapital = 0.0;
        $ledger        = [];
        if ($member) {
            $memberQty     = $this->model->memberQuantity($memberId, $shareValue);
            $memberCapital = $this->model->memberCapital($memberId);
            $ledger        = $this->model->ledgerForMember($memberId, $shareValue);
        }

        $this->render('shares/member', [
            'pageTitle'     => 'Member Share Position — ' . APP_NAME,
            'breadcrumbs'   => [
                ['label' => 'Shares', 'url' => APP_URL . '/index.php?page=shares'],
                ['label' => 'Member Position', 'url' => ''],
            ],
            'member'        => $member,
            'shareValue'    => $shareValue,
            'memberQty'     => $memberQty,
            'memberCapital' => $memberCapital,
            'ownershipPct'  => ShareModel::ownershipPercentage($memberQty, $totalQty),
            'ledger'        => $ledger,
        ]);
    }

    // ----------------------------------------------------------------
    // STAGE 3 — HISTORICAL / OPENING SHARE ENTRY (write, admin/treasurer only)
    // ----------------------------------------------------------------
    public function historicalCreate(): void
    {
        $this->requireWriteAccess();

        $this->render('shares/historical-create', [
            'pageTitle'   => 'Record Historical Shares — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Shares', 'url' => APP_URL . '/index.php?page=shares'],
                ['label' => 'Record Historical Shares', 'url' => ''],
            ],
            'shareValue'  => $this->currentShareValue(),
            'today'       => date('Y-m-d'),
            'csrfToken'   => $this->getCsrf(),
            'error'       => Session::flash('error'),
            'oldInput'    => Session::flash('old_input') ?? [],
            'duplicateWarning' => Session::flash('duplicate_warning'),
        ]);
    }

    public function historicalStore(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=share-historical-create');
            return;
        }

        $source = $_POST['source'] ?? '';
        $memberId = (int)($_POST['member_id'] ?? 0);
        $userId = (int)Session::get('user_id');

        try {
            if ($source === 'retained') {
                $id = $this->model->createHistoricalRetained([
                    'member_id'         => $memberId,
                    'transaction_date'  => $_POST['transaction_date'] ?? '',
                    'retained_amount'   => $_POST['retained_amount'] ?? 0,
                    'confirm_duplicate' => !empty($_POST['confirm_duplicate']),
                ], $userId);
            } elseif ($source === 'purchase') {
                $id = $this->model->createHistoricalPurchase([
                    'member_id'         => $memberId,
                    'transaction_date'  => $_POST['transaction_date'] ?? '',
                    'quantity'          => $_POST['quantity'] ?? 0,
                    'confirm_duplicate' => !empty($_POST['confirm_duplicate']),
                ], $userId);
            } else {
                throw new InvalidArgumentException('Select a valid share source (Retained Savings or Bought Shares).');
            }

            Session::flash('success', 'Historical share entry recorded.');
            $this->redirect(APP_URL . '/index.php?page=share-member&member_id=' . $memberId);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            if (str_starts_with($message, 'DUPLICATE:')) {
                Session::flash('duplicate_warning', substr($message, strlen('DUPLICATE:')));
            } else {
                Session::flash('error', $message);
            }
            Session::flash('old_input', $_POST);
            $this->redirect(APP_URL . '/index.php?page=share-historical-create');
        }
    }

    // ----------------------------------------------------------------
    // STAGE 4-C — CURRENT SHARE TRANSACTION RECORDING
    // (write: admin/treasurer/cashier/office_admin -- see
    // requireCurrentTransactionAccess() above)
    // ----------------------------------------------------------------
    public function currentTransactionCreate(): void
    {
        $this->requireCurrentTransactionAccess();

        $this->render('shares/current-transaction-create', [
            'pageTitle'   => 'Record Share Transaction — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Shares', 'url' => APP_URL . '/index.php?page=shares'],
                ['label' => 'Record Share Transaction', 'url' => ''],
            ],
            'shareValue'  => $this->currentShareValue(),
            'today'       => date('Y-m-d'),
            'csrfToken'   => $this->getCsrf(),
            'error'       => Session::flash('error'),
            'oldInput'    => Session::flash('old_input') ?? [],
            'duplicateWarning'         => Session::flash('duplicate_warning'),
            'externalDuplicateWarning' => Session::flash('external_duplicate_warning'),
        ]);
    }

    public function currentTransactionStore(): void
    {
        $this->requireCurrentTransactionAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=share-transaction-create');
            return;
        }

        $memberId = (int)($_POST['member_id'] ?? 0);
        $userId   = (int)Session::get('user_id');

        try {
            $result = $this->model->createCurrentTransaction([
                'member_id'                  => $memberId,
                'transaction_date'           => $_POST['transaction_date'] ?? '',
                'amount'                     => $_POST['amount'] ?? null,
                'payment_method'             => $_POST['payment_method'] ?? '',
                'external_reference'         => $_POST['external_reference'] ?? '',
                'confirm_duplicate'          => !empty($_POST['confirm_duplicate']),
                'confirm_external_duplicate' => !empty($_POST['confirm_external_duplicate']),
            ], $userId);

            Session::flash('success', 'Share transaction ' . $result['reference_number'] . ' recorded.');
            $this->redirect(APP_URL . '/index.php?page=share-member&member_id=' . $memberId);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            if (str_starts_with($message, 'EXTERNAL_DUPLICATE:')) {
                Session::flash('external_duplicate_warning', substr($message, strlen('EXTERNAL_DUPLICATE:')));
            } elseif (str_starts_with($message, 'DUPLICATE:')) {
                Session::flash('duplicate_warning', substr($message, strlen('DUPLICATE:')));
            } else {
                Session::flash('error', $message);
            }
            Session::flash('old_input', $_POST);
            $this->redirect(APP_URL . '/index.php?page=share-transaction-create');
        }
    }
}
