<?php
/**
 * Member-specific sidebar — Stage 8 (Role Dashboard, Sidebar & Workflow
 * Refinement, 2026-09).
 *
 * Included by sidebar.php ONLY when $userRole === 'member'. Before this
 * stage, member fell through to the generic full-featured sidebar.php
 * content (the same one admin sees), showing Members/Savings/Loans/
 * Withdrawals/Finance/Reports/Accounting/Settings links -- every one of
 * them a dead end, since a direct grep across every controller confirms
 * the 'member' role currently holds ZERO backend-granted capability
 * anywhere in this application (no self-service "my savings"/"my loans"
 * portal exists yet). There are also zero real users assigned this role
 * today.
 *
 * This file deliberately shows nothing beyond the dashboard itself --
 * inventing links to a member self-service portal that does not exist in
 * the backend would violate the "do not add new features" rule for this
 * stage (a real member portal, if wanted, is a separate, larger feature
 * addition for a future stage, not a Stage 8 UI-refinement fix). This is
 * intentionally honest about current capability rather than decorative.
 */
?>
<div class="sb-sidenav-menu-heading">My Account</div>
<a class="nav-link <?= isActive('dashboard') ?>" href="<?= APP_URL ?>/index.php?page=dashboard">
    <div class="sb-nav-link-icon"><i class="bi bi-house"></i></div>
    Dashboard
</a>
<div class="px-3 py-2 small text-muted">
    Self-service account access is not yet available. Please contact your club administrator for account information.
</div>
