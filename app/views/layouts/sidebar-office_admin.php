<?php
/**
 * Office Administrator sidebar — "member-facing operational administrator
 * + collections," per explicit user design (2026-09 Office Admin audit).
 * Included by sidebar.php ONLY when $userRole === 'office_admin'; PHP
 * include() shares the parent scope, so every $canSeeXxx flag computed at
 * the top of sidebar.php is already available here unchanged -- this file
 * adds no new backend grant beyond the one explicitly approved exception
 * (Weekly Savings, see below) and removes none; it only reorganizes
 * office_admin's existing, already-role-gated destinations.
 *
 * Deliberately excludes, because the backend genuinely excludes them (not
 * a hidden link): Loan Register/origination, Withdrawals, Expenses, Other
 * Income, Internal Vouchers, Investments, Accounting, Administration,
 * Users/Roles, System Settings, Audit Logs.
 *
 * Weekly Reports is the one place this role gets narrower-than-controller
 * treatment deliberately: WeeklyReportController::savings() was opened to
 * office_admin (WeeklyReportController.php constructor + blockOfficeAdmin()
 * guard on loans()/repayments()/overdue()) -- only the Savings link is
 * built here, matching that exact backend boundary.
 */
?>
<!-- ── MEMBERS ────────────────────────────────────────────── -->
<?php if ($canSeeMembers): ?>
<div class="sb-sidenav-menu-heading">Members</div>

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
        <a class="nav-link <?= isActive('members') ?>" href="<?= APP_URL ?>/index.php?page=members">
            <i class="bi bi-list-ul me-2"></i> Member Directory
        </a>
        <?php if ($canRegisterMember): ?>
        <a class="nav-link <?= isActive('member-add') ?>" href="<?= APP_URL ?>/index.php?page=member-add">
            <i class="bi bi-person-plus me-2"></i> Add / Register Member
        </a>
        <?php endif; ?>
    </nav>
</div>
<?php endif; ?>

<!-- ── SAVINGS ────────────────────────────────────────────── -->
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
        <a class="nav-link <?= isActive('savings') ?>" href="<?= APP_URL ?>/index.php?page=savings">
            <i class="bi bi-list-ul me-2"></i> Savings Register
        </a>
        <?php endif; ?>
        <?php // $canRecordDeposit (shared flag) is admin/treasurer/cashier
              // only -- it predates office_admin's deposit grant and does
              // not reflect SavingsAccountController::requireDepositAccess()
              // (admin/treasurer/cashier/office_admin). Checked directly
              // here rather than reusing that stale shared flag. ?>
        <a class="nav-link <?= isActive('savings-add') ?>" href="<?= APP_URL ?>/index.php?page=savings-add">
            <i class="bi bi-plus-circle me-2"></i> Record Deposit
        </a>
        <?php if ($canSeeSavingsAccounts): ?>
        <a class="nav-link <?= isActive('savings-accounts') ?>" href="<?= APP_URL ?>/index.php?page=savings-accounts">
            <i class="bi bi-bank me-2"></i> Savings Accounts
        </a>
        <?php endif; ?>
        <a class="nav-link <?= isActive('savings-account-open') ?>" href="<?= APP_URL ?>/index.php?page=savings-account-open">
            <i class="bi bi-bank2 me-2"></i> Open Savings Account
        </a>
    </nav>
</div>
<?php endif; ?>

<!-- ── SHARES ─────────────────────────────────────────────── -->
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
        <a class="nav-link <?= isActive('shares') ?>" href="<?= APP_URL ?>/index.php?page=shares">
            <i class="bi bi-list-ul me-2"></i> Overview
        </a>
        <?php if ($canRecordShareTransaction): ?>
        <a class="nav-link <?= isActive('share-transaction-create') ?>" href="<?= APP_URL ?>/index.php?page=share-transaction-create">
            <i class="bi bi-plus-circle me-2"></i> Record Share Transaction
        </a>
        <?php endif; ?>
        <a class="nav-link <?= isActive('report-shares') ?>" href="<?= APP_URL ?>/index.php?page=report-shares">
            <i class="bi bi-file-bar-graph me-2"></i> Reports
        </a>
    </nav>
</div>
<?php endif; ?>

<!-- ── LOAN REPAYMENTS ────────────────────────────────────── -->
<?php if ($canSeeRepayments): ?>
<a class="nav-link <?= $loansOpen ? '' : 'collapsed' ?>"
   href="#officeAdminRepaymentsMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $loansOpen ? 'true' : 'false' ?>"
   aria-controls="officeAdminRepaymentsMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-arrow-down-circle"></i></div>
    Loan Repayments
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $loansOpen ? 'show' : '' ?>" id="officeAdminRepaymentsMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <a class="nav-link <?= isActive('repayments') ?>" href="<?= APP_URL ?>/index.php?page=repayments">
            <i class="bi bi-list-ul me-2"></i> Repayment Register
        </a>
        <?php if ($canAddRepayment): ?>
        <a class="nav-link <?= isActive('repayment-add') ?>" href="<?= APP_URL ?>/index.php?page=repayment-add">
            <i class="bi bi-plus-circle me-2"></i> Record Repayment
        </a>
        <?php endif; ?>
    </nav>
</div>
<?php endif; ?>

<!-- ── FEES & CHARGES ─────────────────────────────────────── -->
<?php if ($canSeeFees):
    $officeAdminFeesOpen = in_array($currentPage, ['fee-charges', 'fee-charge-form'], true);
?>
<a class="nav-link <?= $officeAdminFeesOpen ? '' : 'collapsed' ?>"
   href="#officeAdminFeesMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $officeAdminFeesOpen ? 'true' : 'false' ?>"
   aria-controls="officeAdminFeesMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-cash-coin"></i></div>
    Fees & Charges
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $officeAdminFeesOpen ? 'show' : '' ?>" id="officeAdminFeesMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <a class="nav-link <?= isActive('fee-charges') ?>" href="<?= APP_URL ?>/index.php?page=fee-charges">
            <i class="bi bi-list-ul me-2"></i> Charge Ledger
        </a>
        <?php if ($canCollectFee): ?>
        <a class="nav-link <?= isActive('fee-charge-form') ?>" href="<?= APP_URL ?>/index.php?page=fee-charge-form">
            <i class="bi bi-plus-circle me-2"></i> Record Fee
        </a>
        <?php endif; ?>
    </nav>
</div>
<?php endif; ?>

<!-- ── STATEMENTS ─────────────────────────────────────────── -->
<?php if ($canSeeStatements): ?>
<div class="sb-sidenav-menu-heading">Statements</div>
<a class="nav-link <?= isActive('statements') ?>" href="<?= APP_URL ?>/index.php?page=statements">
    <div class="sb-nav-link-icon"><i class="bi bi-file-person-fill"></i></div>
    Member Statements
</a>
<?php endif; ?>

<!-- ── REPORTS ────────────────────────────────────────────── -->
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
        <a class="nav-link <?= isActive('report-members') ?>" href="<?= APP_URL ?>/index.php?page=report-members">
            <i class="bi bi-people me-2"></i> Members Report
        </a>
        <a class="nav-link <?= isActive('report-savings') ?>" href="<?= APP_URL ?>/index.php?page=report-savings">
            <i class="bi bi-piggy-bank me-2"></i> Savings Report
        </a>
        <!-- WeeklyReportController::savings() only -- loans()/repayments()/
             overdue() are explicitly blocked for office_admin server-side. -->
        <a class="nav-link <?= isActive('weekly-savings') ?>" href="<?= APP_URL ?>/index.php?page=weekly-savings">
            <i class="bi bi-calendar-week me-2"></i> Weekly Savings
        </a>
    </nav>
</div>
<?php endif; ?>
