<?php
/**
 * Vice Chairman sidebar — Stage 23 (Board Governance Roles, 2026-09).
 * Included by sidebar.php ONLY when $userRole === 'vice_chairman'; PHP
 * include() shares the parent scope, so every $canSeeXxx / $xxxOpen flag
 * computed at the top of sidebar.php is already available here unchanged.
 *
 * Vice Chairman is a full deputy/alternate approver for Chairman across
 * every workflow Chairman already approves (Loan/Loan Application,
 * Investment, Internal Voucher, Member Adjustment, Opening Balance, Loan
 * Provisioning Review/Finalize) -- a management governance decision
 * (2026-09), technically enforced in each controller's own
 * requireApproverAccess()-equivalent gate, not merely by this file. This
 * sidebar mirrors sidebar-chairman.php's structure closely (same
 * destinations Vice Chairman is now equally authorized to reach) with
 * one addition -- an explicit Loan Applications link alongside the Loan
 * Register, matching Vice Chairman's shared approval authority there via
 * LoanRoleAccessTrait -- and reuses the exact same $canSeeXxx flags,
 * already independently widened to include vice_chairman at the top of
 * sidebar.php wherever Vice Chairman's backend access actually extends.
 *
 * The sidebar is navigation only, not the security layer: every
 * destination below independently re-checks vice_chairman server-side.
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

<!-- ── OPERATIONS OVERSIGHT ────────────────────────────────── -->
<div class="sb-sidenav-menu-heading">Operations Oversight</div>

<?php if ($canSeeSavingsSection): ?>
<a class="nav-link <?= $savingsOpen ? '' : 'collapsed' ?>"
   href="#viceChairmanSavingsMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $savingsOpen ? 'true' : 'false' ?>"
   aria-controls="viceChairmanSavingsMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-piggy-bank"></i></div>
    Savings
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $savingsOpen ? 'show' : '' ?>" id="viceChairmanSavingsMenu" data-bs-parent="#sidenavAccordion">
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

<?php if ($canSeeShares): ?>
<a class="nav-link <?= $sharesOpen ? '' : 'collapsed' ?>"
   href="#viceChairmanSharesMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $sharesOpen ? 'true' : 'false' ?>"
   aria-controls="viceChairmanSharesMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-pie-chart-fill"></i></div>
    Shares
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $sharesOpen ? 'show' : '' ?>" id="viceChairmanSharesMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <a class="nav-link <?= isActive('shares') ?>" href="<?= APP_URL ?>/index.php?page=shares">
            <i class="bi bi-list-ul me-2"></i> Overview
        </a>
        <a class="nav-link <?= isActive('report-shares') ?>" href="<?= APP_URL ?>/index.php?page=report-shares">
            <i class="bi bi-file-bar-graph me-2"></i> Reports
        </a>
    </nav>
</div>
<?php endif; ?>

<?php if ($canSeeLoansSection): ?>
<a class="nav-link <?= $loansOpen ? '' : 'collapsed' ?>"
   href="#viceChairmanLoansMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $loansOpen ? 'true' : 'false' ?>"
   aria-controls="viceChairmanLoansMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-bank2"></i></div>
    Loans
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $loansOpen ? 'show' : '' ?>" id="viceChairmanLoansMenu" data-bs-parent="#sidenavAccordion">
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
        <!-- No "Record Loan"/"Record Repayment" links: Vice Chairman is an
             approver, not the maker or the collector, matching Chairman's
             own role contract exactly. -->
    </nav>
</div>
<?php endif; ?>

<!-- ── FINANCIAL OVERSIGHT ────────────────────────────────── -->
<?php if ($canSeeVouchers || $canSeeInvestments): ?>
<div class="sb-sidenav-menu-heading">Financial Oversight</div>
<a class="nav-link <?= $financeOpen ? '' : 'collapsed' ?>"
   href="#viceChairmanFinanceMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $financeOpen ? 'true' : 'false' ?>"
   aria-controls="viceChairmanFinanceMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-wallet2"></i></div>
    Finance
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $financeOpen ? 'show' : '' ?>" id="viceChairmanFinanceMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <?php if ($canSeeVouchers): ?>
        <a class="nav-link <?= isActiveGroup(['internal-vouchers','internal-voucher-view']) ?>" href="<?= APP_URL ?>/index.php?page=internal-vouchers">
            <i class="bi bi-journal-check me-2"></i> Internal Vouchers
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
   href="#viceChairmanReportsMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $reportsOpen ? 'true' : 'false' ?>"
   aria-controls="viceChairmanReportsMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-bar-chart-line-fill"></i></div>
    Reports
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $reportsOpen ? 'show' : '' ?>" id="viceChairmanReportsMenu" data-bs-parent="#sidenavAccordion">
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
        <?php endif; ?>
    </nav>
</div>
<?php endif; ?>

<!-- ── ACCOUNTING (view + the workflows Vice Chairman approves) ──── -->
<?php if ($canSeeAccountingStatements || $canSeeOpeningBalances || $canSeeMemberAdjustments || $canSeeLoanProvisioning): ?>
<div class="sb-sidenav-menu-heading">Accounting</div>
<a class="nav-link <?= ($accountingOpen || $financialReportsOpen) ? '' : 'collapsed' ?>"
   href="#viceChairmanAccountingMenu" data-bs-toggle="collapse"
   aria-expanded="<?= ($accountingOpen || $financialReportsOpen) ? 'true' : 'false' ?>"
   aria-controls="viceChairmanAccountingMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-journal-bookmark-fill"></i></div>
    Accounting
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= ($accountingOpen || $financialReportsOpen) ? 'show' : '' ?>" id="viceChairmanAccountingMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
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
        <?php if ($canSeeOpeningBalances): ?>
        <a class="nav-link <?= isActive('opening-balances') ?>" href="<?= APP_URL ?>/index.php?page=opening-balances">
            <i class="bi bi-clipboard2-check me-2"></i> Opening Balances
        </a>
        <?php endif; ?>
        <?php if ($canSeeMemberAdjustments): ?>
        <a class="nav-link <?= isActiveGroup(['member-adjustments','member-adjustment-view']) ?>" href="<?= APP_URL ?>/index.php?page=member-adjustments">
            <i class="bi bi-sliders me-2"></i> Account Adjustments
        </a>
        <?php endif; ?>
        <?php if ($canSeeLoanProvisioning): ?>
        <a class="nav-link <?= isActiveGroup(['loan-provisioning','loan-provisioning-view']) ?>" href="<?= APP_URL ?>/index.php?page=loan-provisioning">
            <i class="bi bi-shield-check me-2"></i> Loan Provisioning
        </a>
        <?php endif; ?>
    </nav>
</div>
<?php endif; ?>

<!-- ── GOVERNANCE ─────────────────────────────────────────── -->
<div class="sb-sidenav-menu-heading">Governance</div>
<a class="nav-link <?= isActive('settings-audit') ?>" href="<?= APP_URL ?>/index.php?page=settings-audit">
    <div class="sb-nav-link-icon"><i class="bi bi-journal-text"></i></div>
    Audit Logs
</a>
