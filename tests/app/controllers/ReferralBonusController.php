<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/ReferralBonusModel.php';
require_once APP_PATH  . '/models/MemberModel.php';

/**
 * ReferralBonusController — Referral Commissions & Bonuses.
 *
 * View: admin/treasurer/cashier/viewer. Record a payout: admin/treasurer/
 * cashier (cashier hands these out directly, same tier as collecting a
 * fee) -- deliberately excludes viewer (read-only) and member.
 */
class ReferralBonusController extends Controller
{
    private ReferralBonusModel $model;
    private MemberModel $memberModel;

    public function __construct()
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'treasurer', 'cashier', 'viewer', 'chairman'])) {
            http_response_code(403);
            die('Access denied. You do not have permission to view referral bonuses.');
        }
        $this->model = new ReferralBonusModel();
        $this->memberModel = new MemberModel();
    }

    private function requireWriteAccess(): void
    {
        if (!Session::hasRole(['admin', 'treasurer', 'cashier'])) {
            http_response_code(403);
            die('Access denied. Admin, Treasurer, or Cashier privileges required to record a bonus payout.');
        }
    }

    public function index(): void
    {
        $search = trim($_GET['search'] ?? '');
        $type   = trim($_GET['type'] ?? '');
        $page   = max(1, (int)($_GET['p'] ?? 1));

        $result = $this->model->search($search, $type, $page, 20);
        $totals = $this->model->totals();

        $this->render('referral-bonuses/index', [
            'pageTitle'   => 'Referral Commissions & Bonuses',
            'breadcrumbs' => [
                ['label' => 'Other Finance'],
                ['label' => 'Referral Bonuses'],
            ],
            'bonuses'     => $result['rows'],
            'total'       => $result['total'],
            'pages'       => $result['pages'],
            'currentPage' => $page,
            'search'      => $search,
            'type'        => $type,
            'totals'      => $totals,
            'canWrite'    => Session::hasRole(['admin', 'treasurer', 'cashier']),
            'success'     => Session::flash('success'),
            'error'       => Session::flash('error'),
        ]);
    }

    public function create(): void
    {
        $this->requireWriteAccess();
        $this->render('referral-bonuses/form', [
            'pageTitle'       => 'Record Referral / Bonus Payout',
            'breadcrumbs'     => [
                ['label' => 'Other Finance'],
                ['label' => 'Referral Bonuses', 'url' => APP_URL . '/index.php?page=referral-bonuses'],
                ['label' => 'Record Payout'],
            ],
            'referenceNumber' => $this->model->generateReferenceNumber(),
            'errors'          => Session::flash('form_errors') ?? [],
            'old'             => Session::flash('form_old') ?? [],
            'csrfToken'       => $this->getCsrf(),
        ]);
    }

    public function store(): void
    {
        $this->requireWriteAccess();
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=referral-bonus-create');
            return;
        }

        $input = [
            'bonus_type'             => $_POST['bonus_type'] ?? '',
            'beneficiary_member_id'  => $_POST['beneficiary_member_id'] ?? 0,
            'referred_member_id'     => $_POST['referred_member_id'] ?? null,
            'target_note'            => $this->sanitize($_POST['target_note'] ?? ''),
            'amount'                 => $_POST['amount'] ?? 0,
            'payment_date'           => $_POST['payment_date'] ?? date('Y-m-d'),
            'payment_method'         => $_POST['payment_method'] ?? 'Cash',
            'narration'              => $this->sanitize($_POST['narration'] ?? ''),
        ];

        try {
            $userId = (int)Session::get('user_id');
            $posted = $this->model->recordPayout($input, $userId);

            $this->model->log($userId, 'referral_bonus_recorded',
                "Recorded {$posted['reference_number']} — Shs " . number_format((float)$input['amount'], 2) .
                " ({$input['bonus_type']}) — posted as {$posted['entry_number']}");

            Session::flash('success', "Bonus {$posted['reference_number']} recorded and posted as journal entry {$posted['entry_number']}.");
            $this->redirect(APP_URL . '/index.php?page=referral-bonus-view&id=' . $posted['id']);
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            Session::flash('form_old', $input);
            $this->redirect(APP_URL . '/index.php?page=referral-bonus-create');
        }
    }

    public function view(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $bonus = $this->model->findWithDetails($id);
        if (!$bonus) {
            http_response_code(404);
            die('Referral bonus record not found.');
        }

        $this->render('referral-bonuses/view', [
            'pageTitle'   => $bonus['reference_number'] . ' — Referral Bonus',
            'breadcrumbs' => [
                ['label' => 'Other Finance'],
                ['label' => 'Referral Bonuses', 'url' => APP_URL . '/index.php?page=referral-bonuses'],
                ['label' => $bonus['reference_number']],
            ],
            'bonus'   => $bonus,
            'success' => Session::flash('success'),
        ]);
    }

    public function report(): void
    {
        $this->render('referral-bonuses/report', [
            'pageTitle'   => 'Referral Bonus Report',
            'breadcrumbs' => [
                ['label' => 'Other Finance'],
                ['label' => 'Referral Bonuses', 'url' => APP_URL . '/index.php?page=referral-bonuses'],
                ['label' => 'Report'],
            ],
            'summary' => $this->model->summaryByBeneficiary(),
            'totals'  => $this->model->totals(),
        ]);
    }

    /** AJAX: member search shared by the beneficiary and referred-member pickers. */
    public function memberSearch(): void
    {
        $term = trim($_GET['q'] ?? '');
        if (strlen($term) < 2) {
            $this->json(['members' => []]);
            return;
        }
        $result = $this->memberModel->search($term, 'active', '', '', 1, 10);
        $out = array_map(fn($m) => [
            'id'            => $m['id'],
            'member_number' => $m['member_number'],
            'full_name'     => $m['first_name'] . ' ' . $m['last_name'],
            'phone'         => $m['phone'],
        ], $result['rows']);
        $this->json(['members' => $out]);
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
}
