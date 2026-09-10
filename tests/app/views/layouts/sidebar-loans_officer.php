<?php
/**
 * Loans Officer sidebar — "loan processor, loan monitor, repayment
 * recorder, loan reporting user," per explicit user design (2026-09
 * Loans Officer role refinement). Included by sidebar.php ONLY when
 * $userRole === 'loans_officer'; PHP include() shares the parent scope,
 * so $currentPage/isActive()/isActiveGroup() are already available here.
 *
 * Every link below maps to a route this role is now explicitly, narrowly
 * authorized for -- verified directly against the backend gates, not
 * assumed from the shared sidebar's own flags (which were built for other
 * roles and were never audited for loans_officer specifically):
 *   - Members: MemberController::index()/view() -- auth-only, view-only
 *     (add/edit/delete remain admin/office_admin-gated, unchanged).
 *   - Loan Register / New Loan Application / Record Repayment: unchanged,
 *     pre-existing grants (LoanController::requireOriginateAccess(),
 *     RepaymentController::add()).
 *   - Loan Report / Loan Aging / Loan Repayment Report: NEW narrow grant
 *     via ReportController::requireFinancialReportAccess() widened to
 *     include loans_officer, with an explicit blockLoansOfficer() call
 *     added to every OTHER report action (index/members/savings/shares/
 *     withdrawals/financial) so this does not become broad report access.
 *
 * Deliberately NOT included:
 *   - A standalone "Statements"/"Schedule" menu: LoanController's
 *     loan-statement/loan-schedule/loan-card/loan-whatsapp-schedule
 *     actions are all loan-specific (require a loan id) and were already
 *     auth-only before this stage -- they're reached from within a loan's
 *     own detail page (already linked there), not from a member/loan-
 *     agnostic sidebar entry. Building a dead top-level link for them
 *     would violate "do not create placeholder routes."
 *   - A standalone "Savings" menu: SavingsAccountController's constructor
 *     was deliberately NOT widened for this role (would expose the whole
 *     module). Read-only savings visibility (balance, deposit count,
 *     history) is already available via the pre-existing Member Profile
 *     page (MemberController::view(), auth-only, unrelated to this
 *     stage) -- reached from Member Directory below, not a separate menu.
 *   - Weekly Savings: WeeklyReportController explicitly blocks
 *     loans_officer from this one action (blockLoansOfficer() on
 *     savings()); Weekly Loans/Repayments/Overdue are not linked here
 *     either since they'd duplicate the Loan Monitoring/Reports links
 *     above using the same underlying data at a coarser (weekly) grain --
 *     omitted to avoid clutter, not because they're blocked (they aren't).
 *   - Accounting, Vouchers, Expenses, Other Income, Withdrawals, Fees,
 *     Investments, Administration: zero backend access, confirmed by
 *     the pre-implementation audit -- not shown because there is nothing
 *     to show, not because a link was hidden.
 */
?>
<!-- ── MEMBERS ────────────────────────────────────────────── -->
<div class="sb-sidenav-menu-heading">Members</div>
<a class="nav-link <?= isActive('members') ?>" href="<?= APP_URL ?>/index.php?page=members">
    <div class="sb-nav-link-icon"><i class="bi bi-people"></i></div>
    Member Directory
</a>

<!-- ── LOAN APPLICATIONS ──────────────────────────────────── -->
<?php $loApplicationsOpen = in_array($currentPage, ['loans', 'loan-add', 'loan-edit', 'loan-view', 'loan-member', 'loan-applications', 'loan-application-add', 'loan-application-edit', 'loan-application-view'], true); ?>
<div class="sb-sidenav-menu-heading">Loan Applications</div>
<a class="nav-link <?= $loApplicationsOpen ? '' : 'collapsed' ?>"
   href="#loApplicationsMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $loApplicationsOpen ? 'true' : 'false' ?>"
   aria-controls="loApplicationsMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-bank2"></i></div>
    Loan Applications
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $loApplicationsOpen ? 'show' : '' ?>" id="loApplicationsMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <a class="nav-link <?= isActive('loans') ?>" href="<?= APP_URL ?>/index.php?page=loans">
            <i class="bi bi-list-ul me-2"></i> Loan Register
        </a>
        <a class="nav-link <?= isActive('loan-add') ?>" href="<?= APP_URL ?>/index.php?page=loan-add">
            <i class="bi bi-plus-circle me-2"></i> New Loan Application
        </a>
        <a class="nav-link <?= isActive('loan-applications') ?>" href="<?= APP_URL ?>/index.php?page=loan-applications">
            <i class="bi bi-file-earmark-text me-2"></i> Applications (Approval Workflow)
        </a>
    </nav>
</div>

<!-- ── REPAYMENTS ─────────────────────────────────────────── -->
<?php $loRepaymentsOpen = in_array($currentPage, ['repayments', 'repayment-add', 'repayment-view', 'repayment-loan'], true); ?>
<a class="nav-link <?= $loRepaymentsOpen ? '' : 'collapsed' ?>"
   href="#loRepaymentsMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $loRepaymentsOpen ? 'true' : 'false' ?>"
   aria-controls="loRepaymentsMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-arrow-down-circle"></i></div>
    Repayments
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $loRepaymentsOpen ? 'show' : '' ?>" id="loRepaymentsMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <a class="nav-link <?= isActive('repayment-add') ?>" href="<?= APP_URL ?>/index.php?page=repayment-add">
            <i class="bi bi-plus-circle me-2"></i> Record Repayment
        </a>
        <a class="nav-link <?= isActive('repayments') ?>" href="<?= APP_URL ?>/index.php?page=repayments">
            <i class="bi bi-clock-history me-2"></i> Repayment History
        </a>
    </nav>
</div>

<!-- ── LOAN MONITORING ────────────────────────────────────── -->
<div class="sb-sidenav-menu-heading">Loan Monitoring</div>
<a class="nav-link <?= ($currentPage === 'loans' && ($_GET['status'] ?? '') === 'active') ? 'active' : '' ?>"
   href="<?= APP_URL ?>/index.php?page=loans&status=active">
    <div class="sb-nav-link-icon"><i class="bi bi-bank2"></i></div>
    Active Loans
</a>
<a class="nav-link <?= ($currentPage === 'loans' && ($_GET['filter'] ?? '') === 'overdue') ? 'active' : '' ?>"
   href="<?= APP_URL ?>/index.php?page=loans&filter=overdue">
    <div class="sb-nav-link-icon"><i class="bi bi-exclamation-triangle"></i></div>
    Overdue Loans
</a>
<a class="nav-link <?= isActive('report-loan-aging') ?>" href="<?= APP_URL ?>/index.php?page=report-loan-aging">
    <div class="sb-nav-link-icon"><i class="bi bi-hourglass-split"></i></div>
    Loan Aging
</a>

<!-- ── REPORTS ────────────────────────────────────────────── -->
<?php $loReportsOpen = in_array($currentPage, ['report-loans', 'report-loan-aging', 'report-repayments'], true); ?>
<div class="sb-sidenav-menu-heading">Reports</div>
<a class="nav-link <?= $loReportsOpen ? '' : 'collapsed' ?>"
   href="#loReportsMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $loReportsOpen ? 'true' : 'false' ?>"
   aria-controls="loReportsMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-bar-chart-line-fill"></i></div>
    Reports
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $loReportsOpen ? 'show' : '' ?>" id="loReportsMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <a class="nav-link <?= isActive('report-loans') ?>" href="<?= APP_URL ?>/index.php?page=report-loans">
            <i class="bi bi-bank2 me-2"></i> Loan Report
        </a>
        <a class="nav-link <?= isActive('report-repayments') ?>" href="<?= APP_URL ?>/index.php?page=report-repayments">
            <i class="bi bi-arrow-down-circle me-2"></i> Loan Repayment Report
        </a>
        <a class="nav-link <?= isActive('report-loan-aging') ?>" href="<?= APP_URL ?>/index.php?page=report-loan-aging">
            <i class="bi bi-hourglass-split me-2"></i> Loan Aging
        </a>
    </nav>
</div>
