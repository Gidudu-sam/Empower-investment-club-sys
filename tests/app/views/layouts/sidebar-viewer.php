<?php
/**
 * Viewer-specific sidebar — Stage 8 (Role Dashboard, Sidebar & Workflow
 * Refinement, 2026-09).
 *
 * Included by sidebar.php ONLY when $userRole === 'viewer'. Before this
 * stage, viewer fell through to the generic full-featured sidebar.php
 * content (the same one admin sees) -- every module link regardless of
 * whether viewer's backend role check actually permits it, which is
 * exactly the "misleading navigation" pattern the role model exists to
 * avoid. This file adds no new backend grant; it only lists the
 * destinations viewer's EXISTING role checks already confirm (verified
 * directly in each controller's constructor): AccountingReportController,
 * ChartOfAccountsController, FinancialYearController, StatementController,
 * ReportController, WeeklyReportController, InternalVoucherController
 * (view-only -- no create/approve link, since viewer cannot write or
 * approve). No transaction/mutation/approval action appears here, per
 * the Stage 8 brief's explicit "Viewer: read-only, no transaction
 * mutation or approval actions" requirement.
 */
?>
<div class="sb-sidenav-menu-heading">Reports &amp; Statements</div>
<a class="nav-link <?= isActive('reports') ?>" href="<?= APP_URL ?>/index.php?page=reports">
    <div class="sb-nav-link-icon"><i class="bi bi-bar-chart"></i></div>
    Reports
</a>
<a class="nav-link <?= isActive('report-financial') ?>" href="<?= APP_URL ?>/index.php?page=report-financial">
    <div class="sb-nav-link-icon"><i class="bi bi-clipboard-data"></i></div>
    Financial Summary
</a>
<a class="nav-link <?= isActive('statements') ?>" href="<?= APP_URL ?>/index.php?page=statements">
    <div class="sb-nav-link-icon"><i class="bi bi-file-earmark-text"></i></div>
    Statements
</a>
<a class="nav-link <?= isActive('shares') ?>" href="<?= APP_URL ?>/index.php?page=shares">
    <div class="sb-nav-link-icon"><i class="bi bi-pie-chart-fill"></i></div>
    Shares
</a>
<a class="nav-link <?= isActive('weekly-savings') ?>" href="<?= APP_URL ?>/index.php?page=weekly-savings">
    <div class="sb-nav-link-icon"><i class="bi bi-calendar-week"></i></div>
    Weekly Reports
</a>

<div class="sb-sidenav-menu-heading">Accounting (Read-Only)</div>
<a class="nav-link <?= isActive('chart-of-accounts') ?>" href="<?= APP_URL ?>/index.php?page=chart-of-accounts">
    <div class="sb-nav-link-icon"><i class="bi bi-list-columns"></i></div>
    Chart of Accounts
</a>
<a class="nav-link <?= isActive('financial-years') ?>" href="<?= APP_URL ?>/index.php?page=financial-years">
    <div class="sb-nav-link-icon"><i class="bi bi-calendar-range"></i></div>
    Financial Years
</a>
<a class="nav-link <?= isActive('internal-vouchers') ?>" href="<?= APP_URL ?>/index.php?page=internal-vouchers">
    <div class="sb-nav-link-icon"><i class="bi bi-journal-text"></i></div>
    Internal Vouchers
</a>
