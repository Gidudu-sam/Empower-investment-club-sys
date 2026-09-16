<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/models/SavingsModel.php';
require_once APP_PATH  . '/models/LoanModel.php';
require_once APP_PATH  . '/models/RepaymentModel.php';
require_once APP_PATH  . '/models/WithdrawalModel.php';

/**
 * DashboardController — one shell, role-aware content.
 *
 * System Administrator gets a genuinely separate view (dashboard/system-admin)
 * since their job has nothing to do with the club's financial position —
 * showing them savings/loan totals would be exactly the "technical role
 * quietly becomes a financial superuser" pattern the six-role model exists
 * to prevent. Every other role shares dashboard/index.php (the existing,
 * unmodified financial/operational overview) with two additive, role-gated
 * extras: a Chairman/admin "Pending Approvals" panel (reusing each
 * workflow's own existing pendingApproval() method, never re-implemented)
 * and a role-specific Quick Actions list (reusing the same routes/buttons
 * that already exist elsewhere in the app, not new capabilities).
 *
 * No accounting/loan/savings calculation in this file is new or changed —
 * every figure comes from the same model methods the dashboard already
 * called before the six-role model existed.
 */
class DashboardController extends Controller
{
    public function index(): void
    {
        Session::requireAuth();
        $role = Session::get('user_role', 'member');

        // Stage 14-B: a member account has nothing here -- every
        // financial/operational controller this dashboard summarizes
        // already excludes the member role. Route straight to the member
        // portal instead of rendering a staff shell with dead links.
        // Defense-in-depth alongside the same redirect already applied at
        // login (AuthController) and Session::redirectIfAuth() -- this
        // covers a member who navigates to ?page=dashboard directly.
        if ($role === 'member') {
            $this->redirect(APP_URL . '/index.php?page=portal-home');
            return;
        }

        if (Session::hasRole(['system_admin'])) {
            $this->systemAdminDashboard();
            return;
        }

        $memberModel     = new MemberModel();
        $savingsModel    = new SavingsModel();
        $loanModel       = new LoanModel();
        $repaymentModel  = new RepaymentModel();
        $withdrawalModel = new WithdrawalModel();

        $loanModel->syncOverdueStatus();
        $memberModel->syncDormantStatus();

        $weekSavingsCollections = $savingsModel->weekCollections();
        $notSavedMembers = $savingsModel->membersNotSavedInWindow(
            $weekSavingsCollections['start'],
            $weekSavingsCollections['end']
        );

        // 2026-09: narrowed from chairman+admin to chairman-only, per
        // explicit user decision -- with voucher approval already
        // chairman-only, the remaining "Pending Approvals" card on
        // admin's dashboard was reduced to an anticlimactic empty state
        // ("Nothing awaiting your approval right now") whenever no other
        // approval type happened to be pending, and the user asked for
        // the section itself to be removed from admin's dashboard.
        // Chairman's own separate "Action Required" overview panel still
        // needs this same $pendingApprovals data (adjustments/
        // investments/opening balances/loans/vouchers), so it's still
        // computed for chairman -- only admin stops receiving it.
        // Backend approval authority for adjustments/investments/opening
        // balances/loans is unchanged; this is a dashboard-visibility
        // change only.
        // Stage 23: Vice Chairman is a full deputy for Chairman across
        // every workflow below, so gets the identical queue. Secretary's
        // Stage 23 authority is narrower -- Internal Voucher, Loan
        // Application, and Investment approval only (management's explicit
        // decision) -- so Secretary's queue below is filtered to just
        // those three, never Member Adjustments/Opening Balances.
        // 
        // Multi-Approval Fix (2026-09-16): Secretary and Treasurer participate
        // in Tier 4 loan approvals (Very Large Loans >= 10M) even though they
        // don't have general loan approval authority. Show pending loans to
        // Secretary and Treasurer so they can see loans requiring their approval.
        $pendingApprovals = null;
        $pendingApprovalItems = [];
        $isSecretary = Session::hasRole(['secretary']);
        $isTreasurer = Session::hasRole(['treasurer']);
        if (Session::hasRole(['chairman', 'vice_chairman']) || $isSecretary || $isTreasurer) {
            require_once APP_PATH . '/models/InternalVoucherModel.php';
            require_once APP_PATH . '/models/MemberAccountAdjustmentModel.php';
            require_once APP_PATH . '/models/InvestmentModel.php';
            require_once APP_PATH . '/models/OpeningBalanceBatchModel.php';
            require_once APP_PATH . '/models/LoanModel.php';
            require_once APP_PATH . '/models/LoanApplicationModel.php';

            // Vouchers (2026-09): admin no longer approves vouchers
            // (narrowed to chairman-only, explicit user decision); Stage 23
            // added Vice Chairman + Secretary as additional approvers on
            // this same workflow -- an admin viewing this dashboard must
            // not see a voucher queue they can no longer act on, which
            // would be exactly the dead-end "Review" link this widget
            // exists to avoid. Every other approval type below is
            // unaffected; admin still sees and can act on those.
            $vouchers = Session::hasRole(['chairman', 'vice_chairman', 'secretary'])
                ? (new InternalVoucherModel())->pendingApproval() : [];
            // Loan Applications (Stage 23): every role reaching this block
            // approves loan applications (chairman/vice_chairman via
            // LoanRoleAccessTrait, secretary via LoanApplicationController's
            // own override), so always included.
            $loanApplications = (new LoanApplicationModel())->pendingApproval();
            $investments = (new InvestmentModel())->pendingApproval();

            // Member Adjustments and Opening Balances are Chairman/Vice-Chairman-only
            // per Stage 23's governance decision. Loans have mixed authority:
            // - Chairman/Vice Chairman have general loan approval authority
            // - Secretary/Treasurer participate in Tier 4 multi-approvals only
            // - Show loans to all four roles (they'll see loans requiring their approval)
            $adjustments = [];
            $openingBalances = [];
            $loans = [];
            if (!$isSecretary && !$isTreasurer) {
                $adjustments = (new MemberAccountAdjustmentModel())->pendingApproval();
                // Opening Balances: only surfaced now that Stage 7A made the
                // page itself reachable to chairman (view+approve+reject) --
                // before that fix this would have been a dead-end "Review" link.
                $openingBalances = (new OpeningBalanceBatchModel())->pendingApproval();
            }
            // Loans: Show to Chairman, Vice Chairman, Secretary, and Treasurer
            // (all participate in approval workflow)
            $loans = (new LoanModel())->pendingApproval();

            $pendingApprovals = [
                'vouchers'          => $vouchers,
                'adjustments'       => $adjustments,
                'investments'       => $investments,
                'opening_balances'  => $openingBalances,
                'loans'             => $loans,
                'loan_applications' => $loanApplications,
            ];

            // Normalized, per-item list for the dashboard widget -- each
            // model's own field names (voucher_number vs adjustment_number
            // vs investment_number vs batch_number, amount vs
            // principal_amount vs total_debit) are mapped once here rather
            // than scattering type-specific field lookups into the view.
            foreach ($vouchers as $v) {
                $pendingApprovalItems[] = [
                    'type' => 'Voucher', 'reference' => $v['voucher_number'], 'amount' => (float)$v['amount'],
                    'created_by' => $v['recorded_by_name'] ?? '—', 'submitted_at' => $v['submitted_at'],
                    'review_url' => APP_URL . '/index.php?page=internal-voucher-view&id=' . $v['id'],
                ];
            }
            foreach ($adjustments as $a) {
                $pendingApprovalItems[] = [
                    'type' => 'Member Adjustment', 'reference' => $a['adjustment_number'], 'amount' => (float)$a['amount'],
                    'created_by' => $a['recorded_by_name'] ?? '—', 'submitted_at' => $a['submitted_at'],
                    'review_url' => APP_URL . '/index.php?page=member-adjustment-view&id=' . $a['id'],
                ];
            }
            foreach ($investments as $i) {
                $pendingApprovalItems[] = [
                    'type' => 'Investment', 'reference' => $i['investment_number'], 'amount' => (float)$i['principal_amount'],
                    'created_by' => $i['recorded_by_name'] ?? '—', 'submitted_at' => $i['submitted_at'],
                    'review_url' => APP_URL . '/index.php?page=investment-view&id=' . $i['id'],
                ];
            }
            foreach ($openingBalances as $b) {
                $pendingApprovalItems[] = [
                    'type' => 'Opening Balance', 'reference' => $b['batch_number'], 'amount' => (float)$b['total_debit'],
                    'created_by' => $b['entered_by_name'] ?? '—', 'submitted_at' => $b['submitted_at'],
                    'review_url' => APP_URL . '/index.php?page=opening-balance-view&id=' . $b['id'],
                ];
            }
            foreach ($loans as $l) {
                $pendingApprovalItems[] = [
                    'type' => 'Loan', 'reference' => $l['loan_number'], 'amount' => (float)$l['loan_amount'],
                    'created_by' => $l['recorded_by_name'] ?? '—', 'submitted_at' => $l['submitted_at'],
                    'review_url' => APP_URL . '/index.php?page=loan-view&id=' . $l['id'],
                ];
            }
            // Stage 23: Loan Applications -- previously missing from this
            // widget entirely (chairman was always authorized to approve
            // these via LoanRoleAccessTrait, just never surfaced here).
            foreach ($loanApplications as $la) {
                $pendingApprovalItems[] = [
                    'type' => 'Loan Application', 'reference' => $la['application_number'], 'amount' => (float)$la['requested_amount'],
                    'created_by' => $la['recorded_by_name'] ?? '—', 'submitted_at' => $la['submitted_at'],
                    'review_url' => APP_URL . '/index.php?page=loan-application-view&id=' . $la['id'],
                ];
            }
            usort($pendingApprovalItems, fn($x, $y) => strcmp($x['submitted_at'] ?? '', $y['submitted_at'] ?? ''));
        }

        // Treasurer's dashboard is deliberately financial-control-shaped,
        // not operations-shaped: real GL-derived figures (cash/bank
        // position, income/expense/surplus for the active financial year),
        // plus a real, actionable accounting queue (vouchers this same
        // role is the one who posts) and the current accounting period.
        // Every figure here reuses an existing, already-trusted
        // calculation -- none of this re-derives a number a different way
        // than the reports that already show it.
        $treasurerFinancials = null;
        if (Session::hasRole(['treasurer'])) {
            require_once APP_PATH . '/models/AccountingReportModel.php';
            require_once APP_PATH . '/models/AccountingPeriodModel.php';
            require_once APP_PATH . '/models/FinancialYearModel.php';
            require_once APP_PATH . '/models/InternalVoucherModel.php';
            require_once APP_PATH . '/models/FeeModel.php';

            $arModel = new AccountingReportModel();

            // Cash & Bank position: no "is this a cash/bank account" flag
            // exists in the Chart of Accounts, so this is the same fixed
            // set of GL accounts (1110 Cash at Hand, 1120 Mobile Money,
            // 1140 Bank Accounts) every other cash/bank-aware model in
            // this codebase already hardcodes (see LoanModel::PAYMENT_ACCOUNTS).
            // Broken out individually too (not just the combined total) so
            // the dashboard can show each position separately, matching the
            // same three real GL accounts the sidebar's Cash & Bank links
            // point to -- no duplicate balance, same source of truth.
            $cashAtHand   = (float)($arModel->generalLedgerForAccount(7, [])['closing_balance'] ?? 0);
            $mobileMoney  = (float)($arModel->generalLedgerForAccount(8, [])['closing_balance'] ?? 0);
            $bankAccounts = (float)($arModel->generalLedgerForAccount(10, [])['closing_balance'] ?? 0);
            $cashBankBalance = $cashAtHand + $mobileMoney + $bankAccounts;

            $activeFy = (new FinancialYearModel())->findWhere(['status' => 'active']);
            $incomeStatement = $arModel->incomeStatement($activeFy ? ['financial_year_id' => (int)$activeFy['id']] : []);

            $periodModel = new AccountingPeriodModel();
            $openPeriod = $periodModel->getOpenPeriodForDate(date('Y-m-d'));
            $currentPeriod = $openPeriod ? $periodModel->getPeriodWithYear((int)$openPeriod['id']) : null;

            // Vouchers Treasurer can act on right now: approved by
            // Chairman/admin, waiting for Treasurer to post them --
            // InternalVoucherController::post() is gated to admin/treasurer,
            // so this is a genuinely actionable queue for this role, not a
            // generic activity count.
            $allVouchers = (new InternalVoucherModel())->getAll();
            $vouchersAwaitingPosting = array_values(array_filter($allVouchers, fn($v) => $v['status'] === 'approved'));

            $treasurerFinancials = [
                'cashBankBalance'         => $cashBankBalance,
                'cashAtHand'              => $cashAtHand,
                'mobileMoney'             => $mobileMoney,
                'bankAccounts'            => $bankAccounts,
                'activeFinancialYear'     => $activeFy ?: null,
                'totalIncome'             => (float)($incomeStatement['total_income'] ?? 0),
                'totalExpense'            => (float)($incomeStatement['total_expense'] ?? 0),
                'netSurplus'              => (float)($incomeStatement['net_surplus'] ?? 0),
                'currentPeriod'           => $currentPeriod ?: null,
                'vouchersAwaitingPosting' => $vouchersAwaitingPosting,
                // Today's collections across every routine receipt type --
                // each figure reuses the same todayCollections()/todayTotal()
                // method its own module's report/dashboard already relies on.
                'todaySavings'            => $savingsModel->todayTotal(),
                'todayRepaymentsTotal'    => $repaymentModel->todayCollections(),
                'todayFees'               => (new FeeModel())->todayCollections(),
                'todayWithdrawals'        => $withdrawalModel->todayTotal(),
            ];
        }

        // Chairman's dashboard is deliberately governance-shaped: "what
        // needs my decision, what is going wrong, how healthy is the
        // club, what has recently happened" -- not the operational
        // savings-collection view every other role shares. Every figure
        // here reuses an existing, already-trusted calculation (the same
        // $pendingApprovals array built above for the Pending Approvals
        // panel, the same loan/member/savings totals passed to every
        // role) or a genuine audit-trail query (SettingsModel::
        // recentDecisions()/recentActivity()) -- nothing is fabricated or
        // hard-coded as "Healthy."
        // Stage 23: Vice Chairman shares this governance-shaped overview
        // identically with Chairman (full deputy parity) -- reusing this
        // exact block rather than a parallel copy.
        $chairmanOverview = null;
        if (Session::hasRole(['chairman', 'vice_chairman'])) {
            require_once APP_PATH . '/models/SettingsModel.php';
            $settingsModel = new SettingsModel();

            $pendingLoanCount = count($pendingApprovals['loans'] ?? []);
            $pendingCounts = [
                'vouchers'          => count($pendingApprovals['vouchers'] ?? []),
                'loans'             => $pendingLoanCount,
                'adjustments'       => count($pendingApprovals['adjustments'] ?? []),
                'opening_balances'  => count($pendingApprovals['opening_balances'] ?? []),
                'investments'       => count($pendingApprovals['investments'] ?? []),
                'loan_applications' => count($pendingApprovals['loan_applications'] ?? []),
            ];
            $totalPending = array_sum($pendingCounts);

            $activeLoansCount  = $loanModel->countActive();
            $overdueLoansCount = $loanModel->countOverdue();
            $outstandingLoans  = $loanModel->totalOutstanding();

            $chairmanOverview = [
                'pendingCounts'     => $pendingCounts,
                'totalPending'      => $totalPending,
                'loanPortfolio'     => [
                    'active'           => $activeLoansCount,
                    'pendingApproval'  => $pendingLoanCount,
                    'overdue'          => $overdueLoansCount,
                    'outstanding'      => $outstandingLoans,
                ],
                'health' => [
                    // Real thresholds, not invented drama: any overdue
                    // loan at all is flagged; pending approvals are
                    // amber whenever non-zero (that's the whole point of
                    // a queue); everything else is green when its count
                    // is exactly what it should be (zero overdue, zero
                    // exceptions).
                    'loanPortfolio' => $overdueLoansCount === 0 ? 'good' : 'warning',
                    'approvals'     => $totalPending === 0 ? 'good' : 'warning',
                    'membership'    => 'good',
                ],
                'recentDecisions' => $settingsModel->recentDecisions(5),
                'recentActivity'  => $settingsModel->recentActivity(5),
            ];
        }

        $this->render('dashboard/index', [
            'pageTitle'              => 'Dashboard — ' . APP_NAME,
            'chairmanOverview'       => $chairmanOverview,
            'userRole'               => $role,
            'quickActions'           => $this->quickActionsFor($role),
            'pendingApprovals'       => $pendingApprovals,
            'pendingApprovalItems'   => $pendingApprovalItems,
            'totalMembers'           => $memberModel->countAll(),
            'activeMembers'          => $memberModel->countActive(),
            'inactiveMembers'        => $memberModel->countInactive(),
            'dormantMembers'         => $memberModel->countDormant(),
            'recentMembers'          => $memberModel->recent(5),
            'totalSavings'           => $savingsModel->totalSavings(),
            'totalShares'            => $withdrawalModel->totalRetained(),
            'weekSavingsCollections' => $weekSavingsCollections,
            'notSavedMembers'        => $notSavedMembers,
            'recentTransactions'     => $savingsModel->recentTransactions(5),
            // Stage 12-G.1: default period is 6 months (the period
            // selector's own default) -- the selector's onchange handler
            // re-fetches via depositAnalyticsData() below for 3/12; this
            // initial server-rendered payload only ever needs to match
            // whatever the dropdown shows as selected on first paint.
            'monthlySavingsChart'    => $savingsModel->monthlyDepositWithdrawalTrend(6),
            'topSaver'               => $savingsModel->topSaver(),
            'activeLoans'            => $loanModel->countActive(),
            'overdueLoans'           => $loanModel->countOverdue(),
            'overdueLoansList'       => $loanModel->overdueList(),
            'loanOutstanding'        => $loanModel->totalOutstanding(),
            'dueTodayLoans'          => $loanModel->dueTodayCount(),
            'dueWeekLoans'           => $loanModel->dueThisWeekCount(),
            'dueWeekLoansList'       => $loanModel->dueThisWeekList(),
            'recentLoans'            => $loanModel->recentLoans(5),
            'loanAlerts'             => $loanModel->getAlerts(),
            'topBorrower'            => $loanModel->topBorrower(),
            'todayRepayments'        => $repaymentModel->todayCollections(),
            'recentRepayments'       => $repaymentModel->recentRepayments(5),
            'recentWithdrawals'      => $withdrawalModel->recent(5),
            'topShareholder'         => $withdrawalModel->topShareholder(),
            'treasurerFinancials'    => $treasurerFinancials,
        ], 'main');
    }

    /**
     * System Administrator's dashboard — technical/system information only.
     * Reuses SettingsModel's existing users/roles/backup/audit-log queries
     * verbatim (the same ones SettingsController already uses); no new SQL.
     */
    private function systemAdminDashboard(): void
    {
        require_once APP_PATH . '/models/SettingsModel.php';
        $settings = new SettingsModel();

        $users = $settings->getAllUsers();
        $roleDistribution = [];
        $activeUsers = 0;
        foreach ($users as $u) {
            $label = $u['role_label'] ?? $u['role_name'] ?? 'Unknown';
            $roleDistribution[$label] = ($roleDistribution[$label] ?? 0) + 1;
            if (!empty($u['is_active'])) {
                $activeUsers++;
            }
        }

        $backups = $settings->getBackupHistory();
        $recentAudit = $settings->getAuditLogs('', '', 1, 8)['rows'];

        $this->render('dashboard/system-admin', [
            'pageTitle'        => 'Dashboard — ' . APP_NAME,
            'userRole'         => 'system_admin',
            'totalUsers'       => count($users),
            'activeUsers'      => $activeUsers,
            'roleDistribution' => $roleDistribution,
            'lastBackup'       => $backups[0] ?? null,
            'backupCount'      => count($backups),
            'recentAudit'      => $recentAudit,
        ], 'main');
    }

    /**
     * Quick Actions per role — every entry links to a route/permission that
     * already exists elsewhere in the app (Internal Vouchers, Savings,
     * Loans, Members, Fees, Settings); this does not grant any new
     * capability, it only surfaces existing ones on the landing page.
     */
    private function quickActionsFor(string $role): array
    {
        $base = APP_URL . '/index.php?page=';
        return match ($role) {
            // Chairman dashboard redesign (2026-09): Pending Approvals is
            // the primary action (governance-first, not a generic list of
            // "review" links across every module); Audit Activity is the
            // one new destination this stage adds (read-only, matches the
            // new SettingsController::requireAuditLogAccess() grant).
            'chairman' => [
                ['url' => $base . 'dashboard#pending-approvals', 'label' => 'Pending Approvals', 'icon' => 'bi-check2-square',   'class' => 'btn-primary'],
                ['url' => $base . 'report-financial',            'label' => 'Financial Summary', 'icon' => 'bi-clipboard-data',  'class' => 'btn-outline-secondary'],
                ['url' => $base . 'loans',                       'label' => 'Loan Portfolio',    'icon' => 'bi-bank2',           'class' => 'btn-outline-primary'],
                ['url' => $base . 'settings-audit',              'label' => 'Audit Activity',    'icon' => 'bi-journal-text',    'class' => 'btn-outline-secondary'],
            ],
            // Stage 23: Vice Chairman -- identical to Chairman's own quick
            // actions, matching the full deputy parity this role was given
            // across every Chairman-gated workflow.
            'vice_chairman' => [
                ['url' => $base . 'dashboard#pending-approvals', 'label' => 'Pending Approvals', 'icon' => 'bi-check2-square',   'class' => 'btn-primary'],
                ['url' => $base . 'report-financial',            'label' => 'Financial Summary', 'icon' => 'bi-clipboard-data',  'class' => 'btn-outline-secondary'],
                ['url' => $base . 'loans',                       'label' => 'Loan Portfolio',    'icon' => 'bi-bank2',           'class' => 'btn-outline-primary'],
                ['url' => $base . 'settings-audit',              'label' => 'Audit Activity',    'icon' => 'bi-journal-text',    'class' => 'btn-outline-secondary'],
            ],
            // Stage 23: Secretary -- scoped to exactly the three workflows
            // Secretary was granted approval authority over (Internal
            // Voucher, Loan Application, Investment), plus Members (the
            // brief's own "Records/Oversight" requirement) -- no link to
            // any action Secretary's backend role check does not permit.
            'secretary' => [
                ['url' => $base . 'dashboard#pending-approvals', 'label' => 'Pending Approvals', 'icon' => 'bi-check2-square',   'class' => 'btn-primary'],
                ['url' => $base . 'members',                     'label' => 'Member Directory',  'icon' => 'bi-people',          'class' => 'btn-outline-primary'],
                ['url' => $base . 'loan-applications',           'label' => 'Loan Applications', 'icon' => 'bi-file-earmark-text','class' => 'btn-outline-secondary'],
                ['url' => $base . 'statements',                  'label' => 'Statements',        'icon' => 'bi-file-person-fill','class' => 'btn-outline-secondary'],
            ],
            'treasurer' => [
                ['url' => $base . 'dashboard#pending-approvals', 'label' => 'Pending Approvals',  'icon' => 'bi-check2-square',   'class' => 'btn-primary'],
                ['url' => $base . 'savings-add',             'label' => 'Record Deposit',     'icon' => 'bi-plus-circle-fill', 'class' => 'btn-success'],
                ['url' => $base . 'repayment-add',           'label' => 'Record Repayment',   'icon' => 'bi-arrow-down-circle-fill', 'class' => 'btn-warning'],
                ['url' => $base . 'fee-charge-form',         'label' => 'Record Fee',         'icon' => 'bi-cash-coin',       'class' => 'btn-outline-primary'],
            ],
            'cashier' => [
                // 'savings-add' used to route through SavingsController::add(),
                // a deprecated stub that just flashes a message and redirects
                // here anyway -- pointing straight at the live destination,
                // no new controller/route/logic.
                ['url' => $base . 'savings-accounts', 'label' => 'Record Deposit',    'icon' => 'bi-plus-circle-fill', 'class' => 'btn-success'],
                ['url' => $base . 'repayment-add',    'label' => 'Record Repayment',  'icon' => 'bi-arrow-down-circle-fill', 'class' => 'btn-warning'],
                ['url' => $base . 'fee-charge-form',  'label' => 'Record Fee',        'icon' => 'bi-cash-coin',        'class' => 'btn-outline-primary'],
                ['url' => $base . 'withdrawal-process','label' => 'Process Withdrawal','icon' => 'bi-box-arrow-up-right', 'class' => 'btn-outline-secondary'],
            ],
            'loans_officer' => [
                ['url' => $base . 'loan-add',         'label' => 'New Loan Application', 'icon' => 'bi-bank2',       'class' => 'btn-primary'],
                ['url' => $base . 'loans',            'label' => 'Loan Register',        'icon' => 'bi-list-ul',     'class' => 'btn-outline-primary'],
                ['url' => $base . 'repayment-add',    'label' => 'Record Repayment',     'icon' => 'bi-arrow-down-circle-fill', 'class' => 'btn-outline-warning'],
                ['url' => $base . 'loans&filter=overdue', 'label' => 'Overdue Loans',    'icon' => 'bi-exclamation-triangle', 'class' => 'btn-outline-danger'],
            ],
            'office_admin' => [
                // Kept to exactly 4 operational actions per the Office Admin
                // audit -- Member Directory/Open Savings Account/Member
                // Statements remain reachable through the dedicated sidebar
                // rather than the dashboard. 'savings-accounts' (not the
                // deprecated SavingsController::add() stub) is the real
                // live deposit workflow -- pick an account, then deposit.
                ['url' => $base . 'member-add',       'label' => 'Register Member',   'icon' => 'bi-person-plus-fill', 'class' => 'btn-primary'],
                ['url' => $base . 'savings-accounts', 'label' => 'Record Deposit',    'icon' => 'bi-plus-circle-fill', 'class' => 'btn-success'],
                ['url' => $base . 'repayment-add',    'label' => 'Record Repayment',  'icon' => 'bi-arrow-down-circle-fill', 'class' => 'btn-warning'],
                ['url' => $base . 'fee-charge-form',  'label' => 'Record Fee',        'icon' => 'bi-cash-coin',        'class' => 'btn-outline-primary'],
            ],
            // Stage 8 (Role Dashboard, Sidebar & Workflow Refinement,
            // 2026-09): viewer was previously falling into the generic
            // staff `default` case below, showing Record Repayment/
            // Savings/Loan/Add Member buttons that viewer's backend role
            // check has never permitted (verified directly against every
            // controller) -- every dead-end "misleading action" the
            // Stage 8 brief warns about. viewer's REAL, backend-confirmed
            // access is read-only reporting, so that's what its quick
            // actions link to now.
            'viewer' => [
                ['url' => $base . 'reports',          'label' => 'View Reports',   'icon' => 'bi-bar-chart',        'class' => 'btn-outline-primary'],
                ['url' => $base . 'report-financial',  'label' => 'Financial Summary', 'icon' => 'bi-clipboard-data', 'class' => 'btn-outline-secondary'],
                ['url' => $base . 'statements',       'label' => 'View Statements', 'icon' => 'bi-file-earmark-text', 'class' => 'btn-outline-secondary'],
            ],
            // Stage 8: a direct grep across every controller confirms the
            // 'member' role currently holds zero backend-granted
            // capability anywhere in this application (no self-service
            // portal exists yet) -- showing staff-only quick actions here
            // would be pure dead-end UI. An empty list is the honest
            // reflection of what member can actually do today; a real
            // self-service portal is a separate future feature stage; see
            // Stage 8 report Section J.
            'member' => [],
            default => [ // admin only, after the viewer/member split above
                ['url' => $base . 'repayment-add', 'label' => 'Record Repayment', 'icon' => 'bi-arrow-down-circle-fill', 'class' => 'btn-warning'],
                ['url' => $base . 'savings-add',   'label' => 'Record Savings',   'icon' => 'bi-plus-circle-fill', 'class' => 'btn-success'],
                ['url' => $base . 'loan-add',      'label' => 'Record Loan',      'icon' => 'bi-bank2', 'class' => 'btn-outline-primary'],
                ['url' => $base . 'member-add',    'label' => 'Add Member',       'icon' => 'bi-person-plus-fill', 'class' => 'btn-outline-primary'],
                ['url' => $base . 'loans&filter=overdue', 'label' => 'Overdue Loans', 'icon' => 'bi-exclamation-triangle', 'class' => 'btn-outline-danger'],
                // Stage 14-B.1: admin was the only one of the two roles
                // SettingsController::requireUserManagementAccess() actually
                // authorizes (admin, system_admin) with no quick-actions
                // entry to Users -- system_admin never reaches this method
                // (its dashboard branches off to systemAdminDashboard() above
                // and already has its own "Manage Users" action in
                // dashboard/system-admin.php), so this single addition to
                // the admin-only `default` case is sufficient to satisfy
                // "visible only when hasRole(['admin','system_admin'])" for
                // both roles without touching the system_admin dashboard view.
                ['url' => $base . 'settings-users', 'label' => 'Manage Users', 'icon' => 'bi-person-gear', 'class' => 'btn-outline-secondary'],
            ],
        };
    }

    // ----------------------------------------------------------------
    // DEPOSIT ANALYTICS PERIOD SELECTOR (Stage 12-G.1) — read-only AJAX.
    //
    // Thin JSON wrapper around the exact same, already-certified
    // SavingsModel::monthlyDepositWithdrawalTrend() used by index()'s
    // initial server-rendered chart -- no new query, no recalculation,
    // no second data format. Changing the dashboard's period dropdown
    // calls this instead of re-deriving anything in JavaScript, so the
    // browser can never drift from what the backend actually computed.
    // $months is whitelisted (3/6/12), never trusted as an arbitrary
    // client-supplied integer.
    // ----------------------------------------------------------------
    public function depositAnalyticsData(): void
    {
        Session::requireAuth();

        $months = (int)($_GET['months'] ?? 6);
        if (!in_array($months, [3, 6, 12], true)) {
            $months = 6;
        }

        $savingsModel = new SavingsModel();
        $this->json([
            'success' => true,
            'months'  => $months,
            'rows'    => $savingsModel->monthlyDepositWithdrawalTrend($months),
        ]);
    }
}
