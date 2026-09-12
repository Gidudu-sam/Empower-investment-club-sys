<?php
$currentPage = $_GET['page'] ?? '';
$userRole    = Session::get('user_role', 'member');

$memberPages = ['members','member-add','member-edit','member-view','member-delete','member-status-change'];
$membersOpen = in_array($currentPage, $memberPages);

$savingsPages = ['savings','savings-add','savings-edit','savings-view','savings-member','savings-report',
                 'savings-accounts','savings-account-open','savings-account-voluntary','savings-account-voluntary-store',
                 'savings-account-joint','savings-account-joint-store','savings-account-corporate','savings-account-corporate-store',
                 'savings-account-view','savings-account-statement','savings-account-member-summary',
                 'savings-account-bf-register','savings-account-bf-classify'];
$savingsOpen  = in_array($currentPage, $savingsPages);

$loanPages  = ['loans','loan-add','loan-edit','loan-view','loan-member','loan-pending-approval',
               'loan-applications','loan-application-add','loan-application-edit','loan-application-view',
               'repayments','repayment-add','repayment-view','repayment-loan','repayment-report'];
$loansOpen  = in_array($currentPage, $loanPages);

$withdrawalPages = ['withdrawals','withdrawal-process','withdrawal-view','withdrawal-member','withdrawal-report'];
$withdrawalsOpen = in_array($currentPage, $withdrawalPages);

$sharePages = ['shares','share-member','share-historical-create','share-transaction-create'];
$sharesOpen = in_array($currentPage, $sharePages);

$financePages = ['contributions','expenses','expense-create','expense-store','expense-view','expense-post',
                 'other-income','other-income-create','other-income-store','other-income-view','other-income-post',
                 'internal-vouchers','internal-voucher-create','internal-voucher-store','internal-voucher-view',
                 'internal-voucher-submit','internal-voucher-approve','internal-voucher-reject','internal-voucher-post',
                 'referral-bonuses','referral-bonus-create','referral-bonus-store','referral-bonus-view','referral-bonus-report'];
$financeOpen  = in_array($currentPage, $financePages);

// REPORTS — operational + membership reports, consolidated into one dropdown
$reportPages = ['reports','report-members','report-loan-aging','report-savings','report-shares',
                'report-loans','report-repayments',
                'weekly-savings','weekly-loans','weekly-repayments','weekly-overdue',
                'report-withdrawals','report-financial'];
$reportsOpen = in_array($currentPage, $reportPages);

// ACCOUNTING — setup + records only (financial reporting outputs live in their own section)
$accountingPages = ['chart-of-accounts','account-view','account-create','account-store','account-toggle-status',
                    'accounting-periods','accounting-period-view','accounting-period-create','accounting-period-store',
                    'accounting-period-close-confirm','accounting-period-close',
                    'accounting-period-reopen-confirm','accounting-period-reopen',
                    'financial-years','financial-year-view',
                    'financial-year-close-confirm','financial-year-close',
                    'financial-year-reopen-confirm','financial-year-reopen',
                    'opening-balances','opening-balance-create','opening-balance-store','opening-balance-view',
                    'opening-balance-submit','opening-balance-approve','opening-balance-reject','opening-balance-post',
                    'expense-categories','expense-category-store',
                    'other-income-categories','other-income-category-store',
                    'member-adjustments','member-adjustment-create','member-adjustment-view',
                    'report-general-ledger',
                    'withdrawal-policies','withdrawal-policy-history','withdrawal-policy-create','withdrawal-policy-edit-draft',
                    'loan-provisioning','loan-provisioning-calculate','loan-provisioning-view',
                    'loan-provisioning-report-summary','loan-provisioning-report-by-bucket',
                    'loan-provisioning-report-loan-level','loan-provisioning-report-movement'];
$accountingOpen  = in_array($currentPage, $accountingPages);

// FINANCIAL REPORTS — the high-level financial reporting outputs
$financialReportPages = ['report-trial-balance','report-income-statement','report-balance-sheet'];
$financialReportsOpen = in_array($currentPage, $financialReportPages);

$adminPages = ['settings','settings-general','settings-loans','settings-withdrawals','settings-receipts',
               'settings-users','settings-database','settings-audit'];
$adminOpen = in_array($currentPage, $adminPages);

function isActive(string $page): string {
    global $currentPage;
    return ($currentPage === $page) ? 'active' : '';
}
function isActiveGroup(array $pages): string {
    global $currentPage;
    return in_array($currentPage, $pages) ? 'active' : '';
}

// ------------------------------------------------------------
// Role-aware visibility — each flag mirrors the EXACT backend
// hasRole() array of the controller it links to (re-verified against
// source during the Role Alignment Audit, 2026-09). The sidebar is
// navigation only, not the security layer: every one of these
// destinations independently re-checks the same roles server-side,
// so a wrongly-shown or wrongly-hidden link here cannot grant or
// remove real access — it only affects discoverability.
// ------------------------------------------------------------
// Stage 23: Vice Chairman added wherever Chairman already appears below
// (full deputy oversight parity, matching every backend gate already
// widened for vice_chairman in the corresponding controllers). Secretary
// is added only to the specific flags matching her narrower, explicitly
// scoped Stage 23 authority (Members, Statements, Reports, Financial
// Reports, Vouchers, Investments) -- never to a flag whose backend gate
// was not also widened for secretary.
$canSeeMembers            = Session::hasRole(['admin','treasurer','cashier','viewer','chairman','loans_officer','office_admin','vice_chairman','secretary']);
$canRegisterMember        = Session::hasRole(['admin','office_admin']); // MemberController::requireAddAccess -- role-policy stage: treasurer/cashier removed, Office Administrator is the sole member-registration officer

$canSeeSavingsLedger      = Session::hasRole(['admin','treasurer','cashier','viewer','chairman','vice_chairman']);
$canRecordDeposit         = Session::hasRole(['admin','treasurer','cashier']); // routine entry -- chairman excluded by design
$canSeeSavingsAccounts    = Session::hasRole(['admin','treasurer','cashier','viewer','chairman','office_admin','vice_chairman','secretary']); // SavingsAccountController exact
$canSeeSavingsSection     = $canSeeSavingsLedger || $canSeeSavingsAccounts;

$canSeeWithdrawals        = Session::hasRole(['admin','treasurer','cashier','viewer','chairman']);
$canProcessWithdrawal     = Session::hasRole(['admin','treasurer','cashier']); // routine entry -- chairman excluded by design

$canSeeShares              = Session::hasRole(['admin','treasurer','cashier','viewer','chairman','secretary','vice_chairman','office_admin']); // ShareController constructor
$canRecordShareTransaction = Session::hasRole(['admin','treasurer','cashier','office_admin']); // ShareController::requireCurrentTransactionAccess()
$canRecordHistoricalShares = Session::hasRole(['admin','treasurer']); // ShareController::requireWriteAccess()

$canSeeLoans              = Session::hasRole(['admin','treasurer','viewer','chairman','loans_officer','vice_chairman']);
$canAddLoan               = Session::hasRole(['admin','loans_officer']); // LoanController::requireOriginateAccess exact -- sidebar-redesign: treasurer no longer originates loans
$canSeeRepayments         = Session::hasRole(['admin','treasurer','cashier','loans_officer','chairman','viewer','office_admin','vice_chairman']);
$canAddRepayment          = Session::hasRole(['admin','treasurer','cashier','loans_officer','office_admin']); // RepaymentController::add exact
$canSeeLoansSection       = $canSeeLoans || $canSeeRepayments;

$canSeeExpenses           = Session::hasRole(['admin','treasurer','viewer','chairman']);
$canSeeOtherIncome        = Session::hasRole(['admin','treasurer','viewer','chairman']);
$canSeeVouchers           = Session::hasRole(['admin','treasurer','viewer','chairman','vice_chairman','secretary']); // InternalVoucherController exact
$canSeeReferrals          = Session::hasRole(['admin','treasurer','cashier','viewer','chairman']);
$canSeeFinanceSection     = $canSeeExpenses || $canSeeOtherIncome || $canSeeVouchers || $canSeeReferrals;

$canSeeInvestments        = Session::hasRole(['admin','treasurer','viewer','chairman','vice_chairman','secretary']); // InvestmentController exact
$canSeeStatements         = Session::hasRole(['admin','treasurer','cashier','viewer','chairman','office_admin','vice_chairman','secretary']); // StatementController exact

// Role-policy stage: ReportController now splits Member/Savings reports
// (open to office_admin) from everything loan/financial-specific (kept on
// the original, narrower tier via requireFinancialReportAccess()).
$canSeeReports            = Session::hasRole(['admin','treasurer','cashier','viewer','chairman','office_admin','vice_chairman','secretary']); // ReportController::members()/savings()/index() exact
$canSeeFinancialReports   = Session::hasRole(['admin','treasurer','cashier','viewer','chairman','vice_chairman','secretary']); // ReportController::requireFinancialReportAccess() / WeeklyReportController exact

$canSeeAccountingPeriods  = Session::hasRole(['admin','treasurer','viewer','chairman']);
$canSeeFinancialYears     = Session::hasRole(['admin','treasurer','viewer','chairman']);
// Stage 7A fix: OpeningBalanceController's constructor previously gated
// the whole controller to admin/treasurer only, silently blocking
// chairman from ever reaching approve()/reject() despite those methods'
// own (until-then unreachable) admin/chairman check. Split into a
// view-tier gate (admin,treasurer,chairman) and an explicit write-tier
// check on create/store/submit/post -- chairman now correctly sees this
// link, matching their now-genuinely-reachable view+approve+reject access.
// Stage 23: Vice Chairman added to Opening Balances/Member Adjustments/
// Provisioning specifically -- these are three of the actual workflows
// Vice Chairman now has approval authority over (matches the
// corresponding controllers' widened backend gates exactly). Chart of
// Accounts/General Ledger/Withdrawal Policies/Expense & Other Income
// Categories are accounting SETUP, not an approval workflow either new
// role was granted -- left untouched.
$canSeeOpeningBalances    = Session::hasRole(['admin','treasurer','chairman','vice_chairman']);
$canSeeExpenseCategories  = Session::hasRole(['admin','treasurer','viewer','chairman']);
$canSeeOtherIncomeCategories = Session::hasRole(['admin','treasurer','viewer','chairman']);
$canSeeChartOfAccounts    = Session::hasRole(['admin','treasurer','viewer','chairman']);
$canSeeGeneralLedger      = Session::hasRole(['admin','treasurer','viewer','chairman']);
$canSeeMemberAdjustments  = Session::hasRole(['admin','treasurer','viewer','chairman','vice_chairman']);
$canSeeWithdrawalPolicies = Session::hasRole(['admin','treasurer','viewer','chairman']);
// Stage 21-D — matches LoanProvisioningController's own view-role gate exactly.
// Stage 23: vice_chairman added (deputy for chairman's review/finalize authority).
$canSeeLoanProvisioning   = Session::hasRole(['admin','treasurer','chairman','vice_chairman','loans_officer','system_admin','viewer']);
$canSeeAccountingSection  = $canSeeAccountingPeriods || $canSeeFinancialYears || $canSeeOpeningBalances
                          || $canSeeChartOfAccounts || $canSeeMemberAdjustments || $canSeeWithdrawalPolicies
                          || $canSeeLoanProvisioning;

// Bug fix (Cashier audit, 2026-09): this used to reuse the
// $canSeeFinancialReports name from line 108, silently shadowing that
// ReportController-tier value with AccountingReportController's own
// (narrower, cashier-excluded) gate. The two are genuinely different
// authorizations that happened to share a variable name -- this was
// hiding cashier's already-backend-approved Aging/Shares/Loan/Repayment/
// Withdrawal report links (line ~439) even though nothing in the backend
// ever excluded cashier from them. Renamed so each gate owns its own name.
$canSeeAccountingStatements = Session::hasRole(['admin','treasurer','viewer','chairman','vice_chairman']); // AccountingReportController exact (Trial Balance/Income Statement/Balance Sheet)

$canSeeFees               = Session::hasRole(['admin','treasurer','cashier','office_admin','chairman']);
$canCollectFee            = Session::hasRole(['admin','treasurer','cashier','office_admin']); // FeeController::requireCollectAccess exact

$canSeeAdminPolicySettings = Session::hasRole(['admin']); // SettingsController::requireAdmin (general/loan/withdrawal/receipt settings)
$canSeeAdminTechnical      = Session::hasRole(['admin','system_admin']); // SettingsController::requireSystemAdminOrAdmin (database/audit)
// Office Administrator's brief grant of Users access was explicitly
// revoked -- matches SettingsController::requireUserManagementAccess()
// (admin/system_admin only).
$canSeeUserManagement      = Session::hasRole(['admin','system_admin']);
// Stage 14-B.1: the "Temporary, UI-only" hide that used to sit here
// ($hideAdminSettingsForNow, keyed off role === 'admin') suppressed this
// entire Administration section -- General/Loan/Withdrawal/Receipt
// Settings, Users, Database, Audit Logs -- for the admin role only.
// Verified against the three flags immediately above: admin is already
// backend-authorized for every single link this section renders
// ($canSeeAdminPolicySettings, $canSeeAdminTechnical, $canSeeUserManagement
// are all admin-inclusive), so the hide was pure discoverability loss, not
// a real restriction -- admin's only route to Users was the unlabeled
// "Users" tab inside General Settings. Removed per Stage 14-B.1 (give
// admin a visible path to User Accounts); system_admin's/office_admin's
// own sidebar files are untouched.
$canSeeAdministrationSection = ($canSeeAdminPolicySettings || $canSeeAdminTechnical || $canSeeUserManagement);
?>
<div id="layoutSidenav_nav">
    <nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">

        <!-- Brand mark -->
        <div style="padding:1.25rem 1.25rem .75rem;display:flex;align-items:center;gap:.65rem;">
            <img src="<?= APP_URL ?>/public/images/logo.png" alt="Empower Logo" style="width:36px;height:36px;border-radius:.4rem;object-fit:contain;">
            <div style="line-height:1.15;overflow:hidden;">
                <div style="font-size:.82rem;font-weight:700;color:#fff;white-space:nowrap;">Empower</div>
                <div style="font-size:.54rem;font-weight:500;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.1em;">Investment Club</div>
            </div>
        </div>

        <div class="sb-sidenav-menu">
            <div class="nav">

                <!-- ── Dashboard (no heading) ────────────────── -->
                <a class="nav-link <?= isActive('dashboard') ?>" style="margin-top:.5rem;"
                   href="<?= APP_URL ?>/index.php?page=dashboard">
                    <div class="sb-nav-link-icon"><i class="bi bi-grid-1x2"></i></div>
                    Dashboard
                </a>

                <?php if ($userRole === 'treasurer'): ?>
                <?php include __DIR__ . '/sidebar-treasurer.php'; ?>
                <?php elseif ($userRole === 'cashier'): ?>
                <?php include __DIR__ . '/sidebar-cashier.php'; ?>
                <?php elseif ($userRole === 'office_admin'): ?>
                <?php include __DIR__ . '/sidebar-office_admin.php'; ?>
                <?php elseif ($userRole === 'loans_officer'): ?>
                <?php include __DIR__ . '/sidebar-loans_officer.php'; ?>
                <?php elseif ($userRole === 'chairman'): ?>
                <?php include __DIR__ . '/sidebar-chairman.php'; ?>
                <?php elseif ($userRole === 'vice_chairman'): ?>
                <?php include __DIR__ . '/sidebar-vice_chairman.php'; ?>
                <?php elseif ($userRole === 'secretary'): ?>
                <?php include __DIR__ . '/sidebar-secretary.php'; ?>
                <?php elseif ($userRole === 'system_admin'): ?>
                <?php include __DIR__ . '/sidebar-system_admin.php'; ?>
                <?php elseif ($userRole === 'viewer'): ?>
                <?php include __DIR__ . '/sidebar-viewer.php'; ?>
                <?php elseif ($userRole === 'member'): ?>
                <?php include __DIR__ . '/sidebar-member.php'; ?>
                <?php else: ?>

                <!-- ── MEMBERSHIP ────────────────────────────── -->
                <?php if ($canSeeMembers): ?>
                <div class="sb-sidenav-menu-heading">Membership</div>

                <a class="nav-link <?= $membersOpen ? '' : 'collapsed' ?>"
                   href="#membersMenu" data-bs-toggle="collapse"
                   aria-expanded="<?= $membersOpen ? 'true' : 'false' ?>"
                   aria-controls="membersMenu">
                    <div class="sb-nav-link-icon"><i class="bi bi-people"></i></div>
                    Members
                    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
                </a>
                <div class="collapse <?= $membersOpen ? 'show' : '' ?>" id="membersMenu" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="nav-link <?= isActive('members') ?>"
                           href="<?= APP_URL ?>/index.php?page=members">
                            <i class="bi bi-list-ul me-2"></i> All Members
                        </a>
                        <?php if ($canRegisterMember): ?>
                        <a class="nav-link <?= isActive('member-add') ?>"
                           href="<?= APP_URL ?>/index.php?page=member-add">
                            <i class="bi bi-person-plus me-2"></i> Add Member
                        </a>
                        <?php endif; ?>
                    </nav>
                </div>
                <?php endif; ?>

                <!-- Savings -->
                <?php if ($canSeeSavingsSection): ?>
                <a class="nav-link <?= $savingsOpen ? '' : 'collapsed' ?>"
                   href="#savingsMenu" data-bs-toggle="collapse"
                   aria-expanded="<?= $savingsOpen ? 'true' : 'false' ?>"
                   aria-controls="savingsMenu">
                    <div class="sb-nav-link-icon"><i class="bi bi-piggy-bank"></i></div>
                    Savings
                    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
                </a>
                <div class="collapse <?= $savingsOpen ? 'show' : '' ?>" id="savingsMenu" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <?php if ($canSeeSavingsLedger): ?>
                        <a class="nav-link <?= isActive('savings') ?>"
                           href="<?= APP_URL ?>/index.php?page=savings">
                            <i class="bi bi-list-ul me-2"></i> All Savings
                        </a>
                        <?php endif; ?>
                        <?php if ($canRecordDeposit): ?>
                        <a class="nav-link <?= isActive('savings-add') ?>"
                           href="<?= APP_URL ?>/index.php?page=savings-add">
                            <i class="bi bi-plus-circle me-2"></i> Record Savings
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeSavingsAccounts): ?>
                        <a class="nav-link <?= isActive('savings-accounts') ?>"
                           href="<?= APP_URL ?>/index.php?page=savings-accounts">
                            <i class="bi bi-bank me-2"></i> Savings Accounts
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeSavingsLedger): ?>
                        <a class="nav-link <?= isActive('savings-report') ?>"
                           href="<?= APP_URL ?>/index.php?page=savings-report">
                            <i class="bi bi-file-bar-graph me-2"></i> Reports
                        </a>
                        <?php endif; ?>
                        <?php if (Session::hasRole(['admin', 'treasurer'])): ?>
                        <a class="nav-link <?= isActive('savings-account-bf-register') ?>"
                           href="<?= APP_URL ?>/index.php?page=savings-account-bf-register">
                            <i class="bi bi-clock-history me-2"></i> B/F Register
                        </a>
                        <?php endif; ?>
                    </nav>
                </div>
                <?php endif; ?>

                <!-- Withdrawals -->
                <?php if ($canSeeWithdrawals): ?>
                <a class="nav-link <?= $withdrawalsOpen ? '' : 'collapsed' ?>"
                   href="#withdrawalsMenu" data-bs-toggle="collapse"
                   aria-expanded="<?= $withdrawalsOpen ? 'true' : 'false' ?>"
                   aria-controls="withdrawalsMenu">
                    <div class="sb-nav-link-icon"><i class="bi bi-box-arrow-up-right"></i></div>
                    Withdrawals
                    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
                </a>
                <div class="collapse <?= $withdrawalsOpen ? 'show' : '' ?>" id="withdrawalsMenu" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="nav-link <?= isActive('withdrawals') ?>"
                           href="<?= APP_URL ?>/index.php?page=withdrawals">
                            <i class="bi bi-list-ul me-2"></i> All Withdrawals
                        </a>
                        <?php if ($canProcessWithdrawal): ?>
                        <a class="nav-link <?= isActive('withdrawal-process') ?>"
                           href="<?= APP_URL ?>/index.php?page=withdrawal-process">
                            <i class="bi bi-plus-circle me-2"></i> Process Withdrawal
                        </a>
                        <?php endif; ?>
                        <a class="nav-link <?= isActive('withdrawal-report') ?>"
                           href="<?= APP_URL ?>/index.php?page=withdrawal-report">
                            <i class="bi bi-file-bar-graph me-2"></i> Reports
                        </a>
                    </nav>
                </div>
                <?php endif; ?>

                <!-- Shares -->
                <?php if ($canSeeShares): ?>
                <a class="nav-link <?= $sharesOpen ? '' : 'collapsed' ?>"
                   href="#sharesMenu" data-bs-toggle="collapse"
                   aria-expanded="<?= $sharesOpen ? 'true' : 'false' ?>"
                   aria-controls="sharesMenu">
                    <div class="sb-nav-link-icon"><i class="bi bi-pie-chart-fill"></i></div>
                    Shares
                    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
                </a>
                <div class="collapse <?= $sharesOpen ? 'show' : '' ?>" id="sharesMenu" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="nav-link <?= isActive('shares') ?>"
                           href="<?= APP_URL ?>/index.php?page=shares">
                            <i class="bi bi-list-ul me-2"></i> Overview
                        </a>
                        <?php if ($canRecordShareTransaction): ?>
                        <a class="nav-link <?= isActive('share-transaction-create') ?>"
                           href="<?= APP_URL ?>/index.php?page=share-transaction-create">
                            <i class="bi bi-plus-circle me-2"></i> Record Share Transaction
                        </a>
                        <?php endif; ?>
                        <?php if ($canRecordHistoricalShares): ?>
                        <a class="nav-link <?= isActive('share-historical-create') ?>"
                           href="<?= APP_URL ?>/index.php?page=share-historical-create">
                            <i class="bi bi-clock-history me-2"></i> Record Historical Shares
                        </a>
                        <?php endif; ?>
                        <a class="nav-link <?= isActive('report-shares') ?>"
                           href="<?= APP_URL ?>/index.php?page=report-shares">
                            <i class="bi bi-file-bar-graph me-2"></i> Reports
                        </a>
                    </nav>
                </div>
                <?php endif; ?>

                <!-- Loans -->
                <?php if ($canSeeLoansSection): ?>
                <a class="nav-link <?= $loansOpen ? '' : 'collapsed' ?>"
                   href="#loansMenu" data-bs-toggle="collapse"
                   aria-expanded="<?= $loansOpen ? 'true' : 'false' ?>"
                   aria-controls="loansMenu">
                    <div class="sb-nav-link-icon"><i class="bi bi-bank2"></i></div>
                    Loans
                    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
                </a>
                <div class="collapse <?= $loansOpen ? 'show' : '' ?>" id="loansMenu" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <?php if ($canSeeLoans): ?>
                        <a class="nav-link <?= isActive('loans') ?>"
                           href="<?= APP_URL ?>/index.php?page=loans">
                            <i class="bi bi-list-ul me-2"></i> Loan Register
                        </a>
                        <?php endif; ?>
                        <?php if ($canAddLoan): ?>
                        <a class="nav-link <?= isActive('loan-add') ?>"
                           href="<?= APP_URL ?>/index.php?page=loan-add">
                            <i class="bi bi-plus-circle me-2"></i> Record Loan
                        </a>
                        <?php endif; ?>
                        <?php if ($canAddLoan || Session::hasRole(['admin','chairman','vice_chairman'])): ?>
                        <a class="nav-link <?= isActive('loan-applications') ?>"
                           href="<?= APP_URL ?>/index.php?page=loan-applications">
                            <i class="bi bi-file-earmark-text me-2"></i> Loan Applications
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeRepayments): ?>
                        <a class="nav-link <?= isActive('repayments') ?>"
                           href="<?= APP_URL ?>/index.php?page=repayments">
                            <i class="bi bi-arrow-down-circle me-2"></i> Repayments
                        </a>
                        <?php endif; ?>
                        <?php if ($canAddRepayment): ?>
                        <a class="nav-link <?= isActive('repayment-add') ?>"
                           href="<?= APP_URL ?>/index.php?page=repayment-add">
                            <i class="bi bi-plus-circle me-2"></i> Record Repayment
                        </a>
                        <?php endif; ?>
                    </nav>
                </div>
                <?php endif; ?>

                <!-- Other Finance -->
                <?php if ($canSeeFinanceSection): ?>
                <a class="nav-link <?= $financeOpen ? '' : 'collapsed' ?>"
                   href="#financeMenu" data-bs-toggle="collapse"
                   aria-expanded="<?= $financeOpen ? 'true' : 'false' ?>"
                   aria-controls="financeMenu">
                    <div class="sb-nav-link-icon"><i class="bi bi-cash-coin"></i></div>
                    Other Finance
                    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
                </a>
                <div class="collapse <?= $financeOpen ? 'show' : '' ?>" id="financeMenu" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <?php if ($canSeeExpenses): ?>
                        <a class="nav-link <?= isActive('expenses') ?>"
                           href="<?= APP_URL ?>/index.php?page=expenses">
                            <i class="bi bi-receipt me-2"></i> Expenses
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeOtherIncome): ?>
                        <a class="nav-link <?= isActiveGroup(['other-income','other-income-create','other-income-view']) ?>"
                           href="<?= APP_URL ?>/index.php?page=other-income">
                            <i class="bi bi-cash-stack me-2"></i> Other Income
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeVouchers): ?>
                        <a class="nav-link <?= isActiveGroup(['internal-vouchers','internal-voucher-create','internal-voucher-view']) ?>"
                           href="<?= APP_URL ?>/index.php?page=internal-vouchers">
                            <i class="bi bi-journal-check me-2"></i> Internal Vouchers
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeReferrals): ?>
                        <a class="nav-link <?= isActiveGroup(['referral-bonuses','referral-bonus-create','referral-bonus-view','referral-bonus-report']) ?>"
                           href="<?= APP_URL ?>/index.php?page=referral-bonuses">
                            <i class="bi bi-person-hearts me-2"></i> Referral Bonuses
                        </a>
                        <?php endif; ?>
                    </nav>
                </div>
                <?php endif; ?>

                <!-- Investments -->
                <?php if ($canSeeInvestments): ?>
                <a class="nav-link <?= isActive('investments') ?>"
                   href="<?= APP_URL ?>/index.php?page=investments">
                    <div class="sb-nav-link-icon"><i class="bi bi-graph-up"></i></div>
                    Investments
                </a>
                <?php endif; ?>

                <!-- Statements -->
                <?php if ($canSeeStatements): ?>
                <a class="nav-link <?= isActive('statements') ?>"
                   href="<?= APP_URL ?>/index.php?page=statements">
                    <div class="sb-nav-link-icon"><i class="bi bi-file-person-fill"></i></div>
                    Statements
                </a>
                <?php endif; ?>

                <!-- Fees -->
                <?php if ($canSeeFees && $canCollectFee):
                    $feesOpen = in_array($currentPage, ['fee-charges', 'fee-charge-form', 'fee-report', 'fees']);
                ?>
                <a class="nav-link <?= $feesOpen ? '' : 'collapsed' ?>"
                   href="#feesMenu" data-bs-toggle="collapse"
                   aria-expanded="<?= $feesOpen ? 'true' : 'false' ?>"
                   aria-controls="feesMenu">
                    <div class="sb-nav-link-icon"><i class="bi bi-cash-coin"></i></div>
                    Fees
                    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
                </a>
                <div class="collapse <?= $feesOpen ? 'show' : '' ?>" id="feesMenu" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="nav-link <?= isActive('fee-charges') ?>"
                           href="<?= APP_URL ?>/index.php?page=fee-charges">
                            <i class="bi bi-list-ul me-2"></i> Fee Charges
                        </a>
                        <a class="nav-link <?= isActive('fee-charge-form') ?>"
                           href="<?= APP_URL ?>/index.php?page=fee-charge-form">
                            <i class="bi bi-plus-circle me-2"></i> Record Fee
                        </a>
                        <?php if (Session::hasRole(['admin'])): ?>
                        <a class="nav-link <?= isActive('fees') ?>"
                           href="<?= APP_URL ?>/index.php?page=fees">
                            <i class="bi bi-gear me-2"></i> Manage Fees
                        </a>
                        <?php endif; ?>
                    </nav>
                </div>
                <?php elseif ($canSeeFees): ?>
                <a class="nav-link <?= isActive('fees') || isActive('fee-charges') || isActive('fee-report') ? 'active' : '' ?>"
                   href="<?= APP_URL ?>/index.php?page=<?= Session::hasRole(['admin']) ? 'fees' : 'fee-charges' ?>">
                    <div class="sb-nav-link-icon"><i class="bi bi-cash-coin"></i></div>
                    Fees
                </a>
                <?php endif; ?>

                <!-- ── REPORTS ───────────────────────────────── -->
                <?php if ($canSeeReports): ?>
                <div class="sb-sidenav-menu-heading">Reports</div>

                <a class="nav-link <?= $reportsOpen ? '' : 'collapsed' ?>"
                   href="#reportsMenu" data-bs-toggle="collapse"
                   aria-expanded="<?= $reportsOpen ? 'true' : 'false' ?>"
                   aria-controls="reportsMenu">
                    <div class="sb-nav-link-icon"><i class="bi bi-bar-chart-line-fill"></i></div>
                    Reports
                    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
                </a>
                <div class="collapse <?= $reportsOpen ? 'show' : '' ?>" id="reportsMenu" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="nav-link <?= isActive('reports') ?>"
                           href="<?= APP_URL ?>/index.php?page=reports">
                            <i class="bi bi-speedometer2 me-2"></i> Reports Dashboard
                        </a>

                        <div class="sb-sidenav-menu-heading" style="padding-left:2.5rem;">Member Reports</div>
                        <a class="nav-link <?= isActive('report-members') ?>"
                           href="<?= APP_URL ?>/index.php?page=report-members">
                            <i class="bi bi-people me-2"></i> Members Report
                        </a>

                        <div class="sb-sidenav-menu-heading" style="padding-left:2.5rem;">Savings & Shares</div>
                        <a class="nav-link <?= isActive('report-savings') ?>"
                           href="<?= APP_URL ?>/index.php?page=report-savings">
                            <i class="bi bi-piggy-bank me-2"></i> Savings Report
                        </a>

                        <?php if ($canSeeFinancialReports): ?>
                        <a class="nav-link <?= isActive('report-loan-aging') ?>"
                           href="<?= APP_URL ?>/index.php?page=report-loan-aging">
                            <i class="bi bi-hourglass-split me-2"></i> Aging Report
                        </a>
                        <a class="nav-link <?= isActive('report-shares') ?>"
                           href="<?= APP_URL ?>/index.php?page=report-shares">
                            <i class="bi bi-pie-chart me-2"></i> Shares Report
                        </a>

                        <div class="sb-sidenav-menu-heading" style="padding-left:2.5rem;">Loan Reports</div>
                        <a class="nav-link <?= isActive('report-loans') ?>"
                           href="<?= APP_URL ?>/index.php?page=report-loans">
                            <i class="bi bi-bank2 me-2"></i> Loans Report
                        </a>
                        <a class="nav-link <?= isActive('report-repayments') ?>"
                           href="<?= APP_URL ?>/index.php?page=report-repayments">
                            <i class="bi bi-arrow-down-circle me-2"></i> Loan Repayment Report
                        </a>

                        <div class="sb-sidenav-menu-heading" style="padding-left:2.5rem;">Weekly Reports</div>
                        <a class="nav-link <?= isActive('weekly-savings') ?>"
                           href="<?= APP_URL ?>/index.php?page=weekly-savings">
                            <i class="bi bi-piggy-bank me-2"></i> Savings Deposits
                        </a>
                        <a class="nav-link <?= isActive('weekly-loans') ?>"
                           href="<?= APP_URL ?>/index.php?page=weekly-loans">
                            <i class="bi bi-bank2 me-2"></i> Loan Disbursements
                        </a>
                        <a class="nav-link <?= isActive('weekly-repayments') ?>"
                           href="<?= APP_URL ?>/index.php?page=weekly-repayments">
                            <i class="bi bi-arrow-down-circle me-2"></i> Loan Repayments
                        </a>
                        <a class="nav-link <?= isActive('weekly-overdue') ?>"
                           href="<?= APP_URL ?>/index.php?page=weekly-overdue">
                            <i class="bi bi-exclamation-triangle me-2"></i> Overdue Loans
                        </a>

                        <div class="sb-sidenav-menu-heading" style="padding-left:2.5rem;">Other Reports</div>
                        <a class="nav-link <?= isActive('report-withdrawals') ?>"
                           href="<?= APP_URL ?>/index.php?page=report-withdrawals">
                            <i class="bi bi-box-arrow-up-right me-2"></i> Withdrawals
                        </a>
                        <a class="nav-link <?= isActive('report-financial') ?>"
                           href="<?= APP_URL ?>/index.php?page=report-financial">
                            <i class="bi bi-clipboard-data me-2"></i> Financial Summary
                        </a>
                        <?php endif; ?>
                    </nav>
                </div>
                <?php endif; ?>

                <!-- ── ACCOUNTING ────────────────────────────── -->
                <?php if ($canSeeAccountingSection): ?>
                <div class="sb-sidenav-menu-heading">Accounting</div>

                <a class="nav-link <?= $accountingOpen ? '' : 'collapsed' ?>"
                   href="#accountingMenu" data-bs-toggle="collapse"
                   aria-expanded="<?= $accountingOpen ? 'true' : 'false' ?>"
                   aria-controls="accountingMenu">
                    <div class="sb-nav-link-icon"><i class="bi bi-journal-bookmark-fill"></i></div>
                    Accounting
                    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
                </a>
                <div class="collapse <?= $accountingOpen ? 'show' : '' ?>" id="accountingMenu" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <div class="sb-sidenav-menu-heading" style="padding-left:2.5rem;">Accounting Setup</div>
                        <?php if ($canSeeAccountingPeriods): ?>
                        <a class="nav-link <?= isActive('accounting-periods') ?>"
                           href="<?= APP_URL ?>/index.php?page=accounting-periods">
                            <i class="bi bi-calendar3 me-2"></i> Accounting Periods
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeFinancialYears): ?>
                        <a class="nav-link <?= isActive('financial-years') ?>"
                           href="<?= APP_URL ?>/index.php?page=financial-years">
                            <i class="bi bi-calendar-range me-2"></i> Financial Years
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeOpeningBalances): ?>
                        <a class="nav-link <?= isActive('opening-balances') ?>"
                           href="<?= APP_URL ?>/index.php?page=opening-balances">
                            <i class="bi bi-clipboard2-check me-2"></i> Opening Balances
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeExpenseCategories): ?>
                        <a class="nav-link <?= isActive('expense-categories') ?>"
                           href="<?= APP_URL ?>/index.php?page=expense-categories">
                            <i class="bi bi-tags me-2"></i> Expense Categories
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeOtherIncomeCategories): ?>
                        <a class="nav-link <?= isActive('other-income-categories') ?>"
                           href="<?= APP_URL ?>/index.php?page=other-income-categories">
                            <i class="bi bi-tags me-2"></i> Other Income Categories
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeWithdrawalPolicies): ?>
                        <a class="nav-link <?= isActiveGroup(['withdrawal-policies','withdrawal-policy-history','withdrawal-policy-create','withdrawal-policy-edit-draft']) ?>"
                           href="<?= APP_URL ?>/index.php?page=withdrawal-policies">
                            <i class="bi bi-sliders me-2"></i> Withdrawal Policies
                        </a>
                        <?php endif; ?>

                        <?php if ($canSeeChartOfAccounts || $canSeeGeneralLedger || $canSeeMemberAdjustments): ?>
                        <div class="sb-sidenav-menu-heading" style="padding-left:2.5rem;">Accounting Records</div>
                        <?php if ($canSeeChartOfAccounts): ?>
                        <a class="nav-link <?= isActive('chart-of-accounts') ?>"
                           href="<?= APP_URL ?>/index.php?page=chart-of-accounts">
                            <i class="bi bi-diagram-3 me-2"></i> Chart of Accounts
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeGeneralLedger): ?>
                        <a class="nav-link <?= isActive('report-general-ledger') ?>"
                           href="<?= APP_URL ?>/index.php?page=report-general-ledger">
                            <i class="bi bi-journal-text me-2"></i> General Ledger
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeMemberAdjustments): ?>
                        <a class="nav-link <?= isActiveGroup(['member-adjustments','member-adjustment-create','member-adjustment-view']) ?>"
                           href="<?= APP_URL ?>/index.php?page=member-adjustments">
                            <i class="bi bi-sliders me-2"></i> Account Adjustments
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeLoanProvisioning): ?>
                        <a class="nav-link <?= isActiveGroup(['loan-provisioning','loan-provisioning-calculate','loan-provisioning-view','loan-provisioning-report-summary','loan-provisioning-report-by-bucket','loan-provisioning-report-loan-level','loan-provisioning-report-movement']) ?>"
                           href="<?= APP_URL ?>/index.php?page=loan-provisioning">
                            <i class="bi bi-shield-exclamation me-2"></i> Loan Provisioning
                        </a>
                        <?php endif; ?>
                        <?php endif; ?>
                    </nav>
                </div>
                <?php endif; ?>

                <!-- ── FINANCIAL REPORTS ─────────────────────── -->
                <?php if ($canSeeAccountingStatements): ?>
                <div class="sb-sidenav-menu-heading">Financial Reports</div>

                <a class="nav-link <?= $financialReportsOpen ? '' : 'collapsed' ?>"
                   href="#financialReportsMenu" data-bs-toggle="collapse"
                   aria-expanded="<?= $financialReportsOpen ? 'true' : 'false' ?>"
                   aria-controls="financialReportsMenu">
                    <div class="sb-nav-link-icon"><i class="bi bi-file-earmark-bar-graph"></i></div>
                    Financial Reports
                    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
                </a>
                <div class="collapse <?= $financialReportsOpen ? 'show' : '' ?>" id="financialReportsMenu" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="nav-link <?= isActive('report-trial-balance') ?>"
                           href="<?= APP_URL ?>/index.php?page=report-trial-balance">
                            <i class="bi bi-list-columns-reverse me-2"></i> Trial Balance
                        </a>
                        <a class="nav-link <?= isActive('report-income-statement') ?>"
                           href="<?= APP_URL ?>/index.php?page=report-income-statement">
                            <i class="bi bi-graph-up-arrow me-2"></i> Income Statement
                        </a>
                        <a class="nav-link <?= isActive('report-balance-sheet') ?>"
                           href="<?= APP_URL ?>/index.php?page=report-balance-sheet">
                            <i class="bi bi-bank me-2"></i> Balance Sheet
                        </a>
                    </nav>
                </div>
                <?php endif; ?>

                <!-- ── ADMINISTRATION ────────────────────────── -->
                <?php if ($canSeeAdministrationSection): ?>
                <div class="sb-sidenav-menu-heading">Administration</div>

                <a class="nav-link <?= $adminOpen ? '' : 'collapsed' ?>"
                   href="#settingsMenu" data-bs-toggle="collapse"
                   aria-expanded="<?= $adminOpen ? 'true' : 'false' ?>"
                   aria-controls="settingsMenu">
                    <div class="sb-nav-link-icon"><i class="bi bi-gear-fill"></i></div>
                    Settings
                    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
                </a>
                <div class="collapse <?= $adminOpen ? 'show' : '' ?>" id="settingsMenu" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <?php if ($canSeeAdminPolicySettings): ?>
                        <a class="nav-link <?= isActive('settings-general') || isActive('settings') ? 'active' : '' ?>"
                           href="<?= APP_URL ?>/index.php?page=settings-general">
                            <i class="bi bi-gear me-2"></i> General
                        </a>
                        <a class="nav-link <?= isActive('settings-loans') ?>"
                           href="<?= APP_URL ?>/index.php?page=settings-loans">
                            <i class="bi bi-bank2 me-2"></i> Loan Settings
                        </a>
                        <a class="nav-link <?= isActive('settings-withdrawals') ?>"
                           href="<?= APP_URL ?>/index.php?page=settings-withdrawals">
                            <i class="bi bi-box-arrow-up-right me-2"></i> Withdrawals & Shares
                        </a>
                        <a class="nav-link <?= isActive('settings-receipts') ?>"
                           href="<?= APP_URL ?>/index.php?page=settings-receipts">
                            <i class="bi bi-receipt me-2"></i> Receipts
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeUserManagement): ?>
                        <a class="nav-link <?= isActive('settings-users') ?>"
                           href="<?= APP_URL ?>/index.php?page=settings-users">
                            <i class="bi bi-person-gear me-2"></i> Users
                        </a>
                        <?php endif; ?>
                        <?php if ($canSeeAdminTechnical): ?>
                        <a class="nav-link <?= isActive('settings-database') ?>"
                           href="<?= APP_URL ?>/index.php?page=settings-database">
                            <i class="bi bi-database me-2"></i> Database
                        </a>
                        <a class="nav-link <?= isActive('settings-audit') ?>"
                           href="<?= APP_URL ?>/index.php?page=settings-audit">
                            <i class="bi bi-journal-text me-2"></i> Audit Logs
                        </a>
                        <?php endif; ?>
                    </nav>
                </div>
                <?php endif; ?>

                <?php endif; // end $userRole === 'treasurer' branch ?>

            </div>
        </div>

        <div class="sb-sidenav-footer">
            <div class="small">Logged in as:</div>
            <strong><?= htmlspecialchars(Session::get('user_name', 'User')) ?></strong>
        </div>
    </nav>
</div>
