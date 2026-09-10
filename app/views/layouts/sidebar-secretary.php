<?php
/**
 * Secretary sidebar — Stage 23 (Board Governance Roles, 2026-09).
 * Included by sidebar.php ONLY when $userRole === 'secretary'; PHP
 * include() shares the parent scope, so every $canSeeXxx flag computed at
 * the top of sidebar.php is already available here unchanged.
 *
 * Secretary's Stage 23 authority (management's explicit governance
 * decision) is deliberately narrower than Chairman/Vice Chairman:
 * approval rights on exactly three workflows -- Internal Voucher, Loan
 * Application, Investment -- plus board-level records/reporting oversight
 * (Members, Statements, Reports). Secretary is NOT given Savings/Loan/
 * Repayment operational registers, Member Adjustments, Opening Balances,
 * Loan Provisioning, or any accounting-statement/setup destination --
 * none of those were part of the granted scope, matching the brief's
 * explicit "Secretary should NOT become a cashier or accountant" rule.
 *
 * No separate "My Approvals" / "Approval History" page was built --
 * InternalVoucherController/LoanApplicationController/InvestmentController
 * each already list every record with its current status (pending/
 * approved/rejected) and, on each record's own view page, the full
 * approved_by/at or rejected_by/at audit trail. Reusing those existing,
 * already-audited list+detail pages (per the brief's explicit "do NOT
 * create duplicate approval systems" rule) serves the same purpose as a
 * dedicated history page without inventing new infrastructure.
 *
 * The sidebar is navigation only, not the security layer: every
 * destination below independently re-checks secretary server-side.
 */
?>
<!-- ── GOVERNANCE / APPROVALS ─────────────────────────────── -->
<div class="sb-sidenav-menu-heading">Governance / Approvals</div>
<a class="nav-link <?= isActive('dashboard') ?>" href="<?= APP_URL ?>/index.php?page=dashboard#pending-approvals">
    <div class="sb-nav-link-icon"><i class="bi bi-check2-square"></i></div>
    Pending Approvals
</a>
<?php if ($canSeeVouchers): ?>
<a class="nav-link <?= isActiveGroup(['internal-vouchers','internal-voucher-view']) ?>" href="<?= APP_URL ?>/index.php?page=internal-vouchers">
    <div class="sb-nav-link-icon"><i class="bi bi-journal-check"></i></div>
    Internal Vouchers
</a>
<?php endif; ?>
<a class="nav-link <?= isActiveGroup(['loan-applications','loan-application-view']) ?>" href="<?= APP_URL ?>/index.php?page=loan-applications">
    <div class="sb-nav-link-icon"><i class="bi bi-file-earmark-text"></i></div>
    Loan Applications
</a>
<?php if ($canSeeInvestments): ?>
<a class="nav-link <?= isActive('investments') ?>" href="<?= APP_URL ?>/index.php?page=investments">
    <div class="sb-nav-link-icon"><i class="bi bi-graph-up"></i></div>
    Investments
</a>
<?php endif; ?>

<!-- ── MEMBERS ────────────────────────────────────────────── -->
<?php if ($canSeeMembers): ?>
<div class="sb-sidenav-menu-heading">Members</div>
<a class="nav-link <?= isActiveGroup(['members','member-view']) ?>" href="<?= APP_URL ?>/index.php?page=members">
    <div class="sb-nav-link-icon"><i class="bi bi-people"></i></div>
    Member Directory
</a>
<?php endif; ?>

<!-- ── RECORDS ────────────────────────────────────────────── -->
<?php if ($canSeeStatements || $canSeeReports): ?>
<div class="sb-sidenav-menu-heading">Records</div>
<?php if ($canSeeStatements): ?>
<a class="nav-link <?= isActive('statements') ?>" href="<?= APP_URL ?>/index.php?page=statements">
    <div class="sb-nav-link-icon"><i class="bi bi-file-person-fill"></i></div>
    Statements
</a>
<?php endif; ?>
<?php if ($canSeeReports): ?>
<a class="nav-link <?= isActive('reports') ?>" href="<?= APP_URL ?>/index.php?page=reports">
    <div class="sb-nav-link-icon"><i class="bi bi-bar-chart-line-fill"></i></div>
    Reports
</a>
<?php endif; ?>
<?php endif; ?>

<!-- ── FINANCIAL / OPERATIONAL OVERSIGHT ──────────────────── -->
<?php if ($canSeeReports || $canSeeFinancialReports): ?>
<div class="sb-sidenav-menu-heading">Financial &amp; Operational Oversight</div>
<a class="nav-link <?= isActive('report-savings') ?>" href="<?= APP_URL ?>/index.php?page=report-savings">
    <div class="sb-nav-link-icon"><i class="bi bi-piggy-bank"></i></div>
    Savings Report
</a>
<?php if ($canSeeShares): ?>
<a class="nav-link <?= isActive('shares') ?>" href="<?= APP_URL ?>/index.php?page=shares">
    <div class="sb-nav-link-icon"><i class="bi bi-pie-chart-fill"></i></div>
    Shares
</a>
<?php endif; ?>
<?php if ($canSeeFinancialReports): ?>
<a class="nav-link <?= isActive('report-loans') ?>" href="<?= APP_URL ?>/index.php?page=report-loans">
    <div class="sb-nav-link-icon"><i class="bi bi-bank2"></i></div>
    Loan Report
</a>
<a class="nav-link <?= isActive('report-financial') ?>" href="<?= APP_URL ?>/index.php?page=report-financial">
    <div class="sb-nav-link-icon"><i class="bi bi-clipboard-data"></i></div>
    Financial Summary
</a>
<?php endif; ?>
<?php endif; ?>
