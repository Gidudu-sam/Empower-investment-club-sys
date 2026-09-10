<?php
/**
 * System Administrator sidebar — SA-1 (2026-09, "Full System Settings &
 * Administrative Control").
 *
 * Expands on the first System Administrator stage: the role now owns
 * full CONFIGURATION authority over system/organization/financial-policy
 * settings, on top of the User & Access / Security / Database areas
 * already granted. Every link below matches a backend gate that was
 * explicitly widened for this stage (see SettingsController::
 * requireSettingsAccess(), FinancialYearController::requireWriteAccess(),
 * ChartOfAccountsController::requireAdmin(), AccountingPeriodController::
 * requireWriteAccess(), WithdrawalPolicyController::requireAdmin(),
 * FeeController::requireAdmin()) -- this file adds no access beyond what
 * those gates now allow.
 *
 * Configuration vs. transaction, preserved exactly as instructed:
 * System Administrator can configure the RULES (loan rate tiers,
 * withdrawal percentages, fee definitions, which GL accounts exist,
 * which period is open) but was NOT added to any ordinary transaction
 * gate (record deposit, collect a fee, process a withdrawal, originate/
 * approve/disburse a loan, record an expense, post a voucher, etc.) --
 * none of those controllers were touched by this stage.
 *
 * Still deliberately excluded, because the feature genuinely does not
 * exist (not a hidden link): Roles & Permissions (role_permissions table
 * is empty/unused), Database Restore, Login/Security Activity beyond the
 * per-user "last login" column already on the Users page, Transaction
 * Investigation, Transaction Correction/Reversal, System Health/
 * Diagnostics/Integrity Checks. These are later SA stages, not SA-1.
 */
?>
<!-- ── USER & ACCESS MANAGEMENT ──────────────────────────────── -->
<div class="sb-sidenav-menu-heading">User &amp; Access Management</div>
<a class="nav-link <?= isActive('settings-users') ?>" href="<?= APP_URL ?>/index.php?page=settings-users">
    <div class="sb-nav-link-icon"><i class="bi bi-person-gear"></i></div>
    Users
</a>

<!-- ── SYSTEM SETTINGS ────────────────────────────────────────── -->
<div class="sb-sidenav-menu-heading">System Settings</div>
<a class="nav-link <?= isActive('settings-general') ?>" href="<?= APP_URL ?>/index.php?page=settings-general">
    <div class="sb-nav-link-icon"><i class="bi bi-building"></i></div>
    Club Information
</a>
<a class="nav-link <?= isActive('financial-years') ?>" href="<?= APP_URL ?>/index.php?page=financial-years">
    <div class="sb-nav-link-icon"><i class="bi bi-calendar-range"></i></div>
    Financial Year
</a>
<a class="nav-link <?= isActive('settings-loans') ?>" href="<?= APP_URL ?>/index.php?page=settings-loans">
    <div class="sb-nav-link-icon"><i class="bi bi-bank2"></i></div>
    Loan Settings
</a>
<a class="nav-link <?= isActive('settings-withdrawals') ?>" href="<?= APP_URL ?>/index.php?page=settings-withdrawals">
    <div class="sb-nav-link-icon"><i class="bi bi-box-arrow-up-right"></i></div>
    Withdrawal Settings
</a>
<a class="nav-link <?= isActive('withdrawal-policies') ?>" href="<?= APP_URL ?>/index.php?page=withdrawal-policies">
    <div class="sb-nav-link-icon"><i class="bi bi-sliders"></i></div>
    Withdrawal Policies
</a>
<a class="nav-link <?= isActive('fees') ?>" href="<?= APP_URL ?>/index.php?page=fees">
    <div class="sb-nav-link-icon"><i class="bi bi-cash-coin"></i></div>
    Fees &amp; Charges
</a>
<a class="nav-link <?= isActive('settings-receipts') ?>" href="<?= APP_URL ?>/index.php?page=settings-receipts">
    <div class="sb-nav-link-icon"><i class="bi bi-receipt"></i></div>
    Receipt Settings
</a>
<a class="nav-link <?= isActive('chart-of-accounts') ?>" href="<?= APP_URL ?>/index.php?page=chart-of-accounts">
    <div class="sb-nav-link-icon"><i class="bi bi-list-columns"></i></div>
    Chart of Accounts
</a>
<a class="nav-link <?= isActive('accounting-periods') ?>" href="<?= APP_URL ?>/index.php?page=accounting-periods">
    <div class="sb-nav-link-icon"><i class="bi bi-calendar-check"></i></div>
    Accounting Periods
</a>

<!-- ── SECURITY & MONITORING ──────────────────────────────────── -->
<div class="sb-sidenav-menu-heading">Security &amp; Monitoring</div>
<a class="nav-link <?= isActive('settings-audit') ?>" href="<?= APP_URL ?>/index.php?page=settings-audit">
    <div class="sb-nav-link-icon"><i class="bi bi-journal-text"></i></div>
    Audit Logs
</a>
<a class="nav-link <?= isActive('system-integrity') ?>" href="<?= APP_URL ?>/index.php?page=system-integrity">
    <div class="sb-nav-link-icon"><i class="bi bi-heart-pulse"></i></div>
    System Integrity
</a>
<a class="nav-link <?= isActive('investigation') ?>" href="<?= APP_URL ?>/index.php?page=investigation">
    <div class="sb-nav-link-icon"><i class="bi bi-search"></i></div>
    Transaction Investigation
</a>
<a class="nav-link <?= isActive('corrections') ?>" href="<?= APP_URL ?>/index.php?page=corrections">
    <div class="sb-nav-link-icon"><i class="bi bi-shield-exclamation"></i></div>
    Controlled Corrections
</a>
<a class="nav-link <?= isActive('recovery') ?>" href="<?= APP_URL ?>/index.php?page=recovery">
    <div class="sb-nav-link-icon"><i class="bi bi-arrow-repeat"></i></div>
    Database Recovery
</a>

<!-- ── DATABASE ───────────────────────────────────────────────── -->
<div class="sb-sidenav-menu-heading">Database</div>
<a class="nav-link <?= isActive('settings-database') ?>" href="<?= APP_URL ?>/index.php?page=settings-database">
    <div class="sb-nav-link-icon"><i class="bi bi-database"></i></div>
    Backup Database
</a>
