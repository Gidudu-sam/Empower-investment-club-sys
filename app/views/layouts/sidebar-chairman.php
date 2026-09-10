<?php
/**
 * Chairman / Team Leader sidebar — "governance, approval, oversight," per
 * explicit user design (2026-09 Chairman role refinement). Included by
 * sidebar.php ONLY when $userRole === 'chairman'; PHP include() shares the
 * parent scope, so every $canSeeXxx / $xxxOpen flag computed at the top of
 * sidebar.php is already available here unchanged.
 *
 * Chairman's VIEW access to Members/Savings/Loans/Repayments/Withdrawals/
 * Fees/Expenses/Other Income/Vouchers/Investments/Statements/Reports/
 * Financial Reports/Chart of Accounts/Accounting Periods/Financial Years/
 * Opening Balances/Account Adjustments/Withdrawal Policies is entirely
 * pre-existing and unchanged by this stage -- this file only reorganizes
 * those already-correct destinations, with an Approvals section made the
 * top-level entry point (matching the dashboard's own Pending Approvals
 * panel) and a new Audit Logs link (the one genuine new backend grant this
 * stage makes, via SettingsController::requireAuditLogAccess()).
 *
 * Deliberately narrower than before on four specific actions -- Chart of
 * Accounts create/toggle, Accounting Period create/close/reopen, Financial
 * Year create/edit/activate/close/reopen, and Withdrawal Policy create/
 * edit/toggle are no longer reachable by chairman (moved to Treasurer) --
 * so no "create"/"manage" links for those four appear here, only the view
 * destinations chairman still has.
 */
?>
<!-- ── APPROVALS ──────────────────────────────────────────── -->
<div class="sb-sidenav-menu-heading">Approvals</div>
<a class="nav-link <?= isActive('dashboard') ?>" href="<?= APP_URL ?>/index.php?page=dashboard#pending-approvals">
    <div class="sb-nav-link-icon"><i class="bi bi-check2-square"></i></div>
    Pending Approvals
</a>

<!-- ── MEMBERSHIP (view-only) ─────────────────────────────── -->
<?php if ($canSeeMembers): ?>
<div class="sb-sidenav-menu-heading">Membership</div>
<a class="nav-link <?= isActive('members') ?>" href="<?= APP_URL ?>/index.php?page=members">
    <div class="sb-nav-link-icon"><i class="bi bi-people"></i></div>
    Members
</a>
<?php endif; ?>

<!-- ── FINANCIAL OVERSIGHT ────────────────────────────────── -->
<div class="sb-sidenav-menu-heading">Financial Oversight</div>

<?php if ($canSeeSavingsSection): ?>
<a class="nav-link <?= $savingsOpen ? '' : 'collapsed' ?>"
   href="#chairmanSavingsMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $savingsOpen ? 'true' : 'false' ?>"
   aria-controls="chairmanSavingsMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-piggy-bank"></i></div>
    Savings
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $savingsOpen ? 'show' : '' ?>" id="chairmanSavingsMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <?php if ($canSeeSavingsLedger): ?>
        <a class="nav-link <?= isActive('savings') ?>" href="<?= APP_URL ?>/index.php?page=savings">
            <i class="bi bi-list-ul me-2"></i> Savings Register
        </a>
        <?php endif; ?>
        <?php if ($canSeeSavingsAccounts): ?>
        <a class="nav-link <?= isActive('savings-accounts') ?>" href="<?= APP_URL ?>/index.php?page=savings-accounts">
            <i class="bi bi-bank me-2"></i> Savings Accounts
        </a>
        <?php endif; ?>
    </nav>
</div>
<?php endif; ?>

<?php if ($canSeeWithdrawals): ?>
<a class="nav-link <?= $withdrawalsOpen ? '' : 'collapsed' ?>"
   href="#chairmanWithdrawalsMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $withdrawalsOpen ? 'true' : 'false' ?>"
   aria-controls="chairmanWithdrawalsMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-box-arrow-up-right"></i></div>
    Withdrawals
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $withdrawalsOpen ? 'show' : '' ?>" id="chairmanWithdrawalsMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <a class="nav-link <?= isActive('withdrawals') ?>" href="<?= APP_URL ?>/index.php?page=withdrawals">
            <i class="bi bi-list-ul me-2"></i> Withdrawal Register
        </a>
        <!-- Reverse is an inline action on a withdrawal's own view page
             (WithdrawalController::requireWriteAccess -- admin/treasurer/
             chairman, unchanged), not a separate nav destination. -->
    </nav>
</div>
<?php endif; ?>

<?php if ($canSeeLoansSection): ?>
<a class="nav-link <?= $loansOpen ? '' : 'collapsed' ?>"
   href="#chairmanLoansMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $loansOpen ? 'true' : 'false' ?>"
   aria-controls="chairmanLoansMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-bank2"></i></div>
    Loans
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $loansOpen ? 'show' : '' ?>" id="chairmanLoansMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <?php if ($canSeeLoans): ?>
        <a class="nav-link <?= isActive('loans') ?>" href="<?= APP_URL ?>/index.php?page=loans">
            <i class="bi bi-list-ul me-2"></i> Loan Register
        </a>
        <a class="nav-link <?= isActive('loan-pending-approval') ?>" href="<?= APP_URL ?>/index.php?page=loan-pending-approval">
            <i class="bi bi-hourglass-split me-2"></i> Awaiting Approval
        </a>
        <a class="nav-link <?= isActive('loan-applications') ?>" href="<?= APP_URL ?>/index.php?page=loan-applications">
            <i class="bi bi-file-earmark-text me-2"></i> Loan Applications
        </a>
        <?php endif; ?>
        <?php if ($canSeeRepayments): ?>
        <a class="nav-link <?= isActive('repayments') ?>" href="<?= APP_URL ?>/index.php?page=repayments">
            <i class="bi bi-arrow-down-circle me-2"></i> Repayment Register
        </a>
        <?php endif; ?>
        <!-- No "Record Loan"/"Record Repayment" links: Chairman is the
             approver, not the maker or the collector, per the role contract. -->
    </nav>
</div>
<?php endif; ?>

<?php if ($canSeeFees): ?>
<a class="nav-link <?= isActive('fee-charges') || isActive('fee-report') ? 'active' : '' ?>"
   href="<?= APP_URL ?>/index.php?page=fee-charges">
    <div class="sb-nav-link-icon"><i class="bi bi-cash-coin"></i></div>
    Fees
</a>
<?php endif; ?>

<!-- ── FINANCE (view + approve) ───────────────────────────── -->
<?php if ($canSeeFinanceSection || $canSeeInvestments): ?>
<a class="nav-link <?= $financeOpen ? '' : 'collapsed' ?>"
   href="#chairmanFinanceMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $financeOpen ? 'true' : 'false' ?>"
   aria-controls="chairmanFinanceMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-wallet2"></i></div>
    Finance
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $financeOpen ? 'show' : '' ?>" id="chairmanFinanceMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <?php if ($canSeeExpenses): ?>
        <a class="nav-link <?= isActive('expenses') ?>" href="<?= APP_URL ?>/index.php?page=expenses">
            <i class="bi bi-receipt me-2"></i> Expenses
        </a>
        <?php endif; ?>
        <?php if ($canSeeOtherIncome): ?>
        <a class="nav-link <?= isActiveGroup(['other-income','other-income-create','other-income-view']) ?>" href="<?= APP_URL ?>/index.php?page=other-income">
            <i class="bi bi-cash-stack me-2"></i> Other Income
        </a>
        <?php endif; ?>
        <?php if ($canSeeVouchers): ?>
        <a class="nav-link <?= isActiveGroup(['internal-vouchers','internal-voucher-create','internal-voucher-view']) ?>" href="<?= APP_URL ?>/index.php?page=internal-vouchers">
            <i class="bi bi-journal-check me-2"></i> Internal Vouchers
        </a>
        <?php endif; ?>
        <?php if ($canSeeReferrals): ?>
        <a class="nav-link <?= isActiveGroup(['referral-bonuses','referral-bonus-create','referral-bonus-view','referral-bonus-report']) ?>" href="<?= APP_URL ?>/index.php?page=referral-bonuses">
            <i class="bi bi-person-hearts me-2"></i> Referral Bonuses
        </a>
        <?php endif; ?>
        <?php if ($canSeeInvestments): ?>
        <a class="nav-link <?= isActive('investments') ?>" href="<?= APP_URL ?>/index.php?page=investments">
            <i class="bi bi-graph-up me-2"></i> Investments
        </a>
        <?php endif; ?>
    </nav>
</div>
<?php endif; ?>

<?php if ($canSeeStatements): ?>
<a class="nav-link <?= isActive('statements') ?>" href="<?= APP_URL ?>/index.php?page=statements">
    <div class="sb-nav-link-icon"><i class="bi bi-file-person-fill"></i></div>
    Statements
</a>
<?php endif; ?>

<!-- ── REPORTS ────────────────────────────────────────────── -->
<?php if ($canSeeReports): ?>
<div class="sb-sidenav-menu-heading">Reports</div>
<a class="nav-link <?= $reportsOpen ? '' : 'collapsed' ?>"
   href="#chairmanReportsMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $reportsOpen ? 'true' : 'false' ?>"
   aria-controls="chairmanReportsMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-bar-chart-line-fill"></i></div>
    Reports
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $reportsOpen ? 'show' : '' ?>" id="chairmanReportsMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <a class="nav-link <?= isActive('reports') ?>" href="<?= APP_URL ?>/index.php?page=reports">
            <i class="bi bi-speedometer2 me-2"></i> Reports Dashboard
        </a>
        <a class="nav-link <?= isActive('report-members') ?>" href="<?= APP_URL ?>/index.php?page=report-members">
            <i class="bi bi-people me-2"></i> Members Report
        </a>
        <a class="nav-link <?= isActive('report-savings') ?>" href="<?= APP_URL ?>/index.php?page=report-savings">
            <i class="bi bi-piggy-bank me-2"></i> Savings Report
        </a>
        <?php if ($canSeeFinancialReports): ?>
        <a class="nav-link <?= isActive('report-loans') ?>" href="<?= APP_URL ?>/index.php?page=report-loans">
            <i class="bi bi-bank2 me-2"></i> Loan Report
        </a>
        <a class="nav-link <?= isActive('report-loan-aging') ?>" href="<?= APP_URL ?>/index.php?page=report-loan-aging">
            <i class="bi bi-hourglass-split me-2"></i> Loan Aging
        </a>
        <a class="nav-link <?= isActive('report-repayments') ?>" href="<?= APP_URL ?>/index.php?page=report-repayments">
            <i class="bi bi-arrow-down-circle me-2"></i> Repayment Report
        </a>
        <a class="nav-link <?= isActive('report-shares') ?>" href="<?= APP_URL ?>/index.php?page=report-shares">
            <i class="bi bi-pie-chart me-2"></i> Shares Report
        </a>
        <a class="nav-link <?= isActive('report-withdrawals') ?>" href="<?= APP_URL ?>/index.php?page=report-withdrawals">
            <i class="bi bi-box-arrow-up-right me-2"></i> Withdrawal Report
        </a>
        <a class="nav-link <?= isActive('report-financial') ?>" href="<?= APP_URL ?>/index.php?page=report-financial">
            <i class="bi bi-clipboard-data me-2"></i> Financial Summary
        </a>

        <div class="sb-sidenav-menu-heading" style="padding-left:2.5rem;">Weekly Reports</div>
        <a class="nav-link <?= isActive('weekly-savings') ?>" href="<?= APP_URL ?>/index.php?page=weekly-savings">
            <i class="bi bi-piggy-bank me-2"></i> Savings Deposits
        </a>
        <a class="nav-link <?= isActive('weekly-loans') ?>" href="<?= APP_URL ?>/index.php?page=weekly-loans">
            <i class="bi bi-bank2 me-2"></i> Loan Disbursements
        </a>
        <a class="nav-link <?= isActive('weekly-repayments') ?>" href="<?= APP_URL ?>/index.php?page=weekly-repayments">
            <i class="bi bi-arrow-down-circle me-2"></i> Loan Repayments
        </a>
        <a class="nav-link <?= isActive('weekly-overdue') ?>" href="<?= APP_URL ?>/index.php?page=weekly-overdue">
            <i class="bi bi-exclamation-triangle me-2"></i> Overdue Loans
        </a>
        <?php endif; ?>
    </nav>
</div>
<?php endif; ?>

<!-- ── ACCOUNTING (view; the four write-tier actions moved to Treasurer) ── -->
<?php if ($canSeeAccountingSection || $canSeeAccountingStatements): ?>
<div class="sb-sidenav-menu-heading">Accounting</div>
<a class="nav-link <?= ($accountingOpen || $financialReportsOpen) ? '' : 'collapsed' ?>"
   href="#chairmanAccountingMenu" data-bs-toggle="collapse"
   aria-expanded="<?= ($accountingOpen || $financialReportsOpen) ? 'true' : 'false' ?>"
   aria-controls="chairmanAccountingMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-journal-bookmark-fill"></i></div>
    Accounting
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= ($accountingOpen || $financialReportsOpen) ? 'show' : '' ?>" id="chairmanAccountingMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <?php if ($canSeeChartOfAccounts): ?>
        <a class="nav-link <?= isActive('chart-of-accounts') ?>" href="<?= APP_URL ?>/index.php?page=chart-of-accounts">
            <i class="bi bi-diagram-3 me-2"></i> Chart of Accounts
        </a>
        <?php endif; ?>
        <?php if ($canSeeGeneralLedger): ?>
        <a class="nav-link <?= (isActive('report-general-ledger') && empty($_GET['account_id'])) ? 'active' : '' ?>" href="<?= APP_URL ?>/index.php?page=report-general-ledger">
            <i class="bi bi-journal-text me-2"></i> General Ledger
        </a>
        <?php endif; ?>
        <?php if ($canSeeAccountingStatements): ?>
        <a class="nav-link <?= isActive('report-trial-balance') ?>" href="<?= APP_URL ?>/index.php?page=report-trial-balance">
            <i class="bi bi-list-columns-reverse me-2"></i> Trial Balance
        </a>
        <a class="nav-link <?= isActive('report-income-statement') ?>" href="<?= APP_URL ?>/index.php?page=report-income-statement">
            <i class="bi bi-graph-up-arrow me-2"></i> Income Statement
        </a>
        <a class="nav-link <?= isActive('report-balance-sheet') ?>" href="<?= APP_URL ?>/index.php?page=report-balance-sheet">
            <i class="bi bi-bank me-2"></i> Balance Sheet
        </a>
        <?php endif; ?>
        <?php if ($canSeeAccountingPeriods): ?>
        <a class="nav-link <?= isActive('accounting-periods') ?>" href="<?= APP_URL ?>/index.php?page=accounting-periods">
            <i class="bi bi-calendar3 me-2"></i> Accounting Periods
        </a>
        <?php endif; ?>
        <?php if ($canSeeFinancialYears): ?>
        <a class="nav-link <?= isActive('financial-years') ?>" href="<?= APP_URL ?>/index.php?page=financial-years">
            <i class="bi bi-calendar-range me-2"></i> Financial Years
        </a>
        <?php endif; ?>
        <?php if ($canSeeOpeningBalances): ?>
        <a class="nav-link <?= isActive('opening-balances') ?>" href="<?= APP_URL ?>/index.php?page=opening-balances">
            <i class="bi bi-clipboard2-check me-2"></i> Opening Balances
        </a>
        <?php endif; ?>
        <?php if ($canSeeMemberAdjustments): ?>
        <a class="nav-link <?= isActiveGroup(['member-adjustments','member-adjustment-create','member-adjustment-view']) ?>" href="<?= APP_URL ?>/index.php?page=member-adjustments">
            <i class="bi bi-sliders me-2"></i> Account Adjustments
        </a>
        <?php endif; ?>
        <?php if ($canSeeWithdrawalPolicies): ?>
        <a class="nav-link <?= isActiveGroup(['withdrawal-policies','withdrawal-policy-history']) ?>" href="<?= APP_URL ?>/index.php?page=withdrawal-policies">
            <i class="bi bi-sliders me-2"></i> Withdrawal Policies
        </a>
        <?php endif; ?>
        <!-- No "create"/"manage" links for Chart of Accounts, Accounting
             Periods, Financial Years, or Withdrawal Policies -- those four
             write-tier gates moved to Treasurer this stage; Chairman is
             view-only here now, matching the recommended contract exactly. -->
    </nav>
</div>
<?php endif; ?>

<!-- ── GOVERNANCE ─────────────────────────────────────────── -->
<div class="sb-sidenav-menu-heading">Governance</div>
<a class="nav-link <?= isActive('settings-audit') ?>" href="<?= APP_URL ?>/index.php?page=settings-audit">
    <div class="sb-nav-link-icon"><i class="bi bi-journal-text"></i></div>
    Audit Logs
</a>
