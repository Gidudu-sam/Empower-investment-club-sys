<?php
$currentPage = $_GET['page'] ?? '';
// This partial is shared by every Settings page, so a user-management-only
// visitor (Office Administrator) was previously shown every tab, including
// admin-only policy pages that would just redirect them away with "Access
// denied" -- filtered here to match each page's own real backend gate
// (SettingsController::requireSettingsAccess() vs requireUserManagementAccess()
// vs requireSystemAdminOrAdmin()), not a new authorization mechanism.
// SA-1 (System Administrator role refinement, 2026-09): widened to
// include system_admin, matching requireSettingsAccess()'s own widened
// gate -- configuration authority, not financial transaction authority.
$canSeePolicyTabs      = Session::hasRole(['admin', 'system_admin']);
// WithdrawalPolicyController and ChartOfAccountsController/
// AccountingPeriodController all widened the same way in SA-1 -- their
// own view gates already include system_admin, so no separate flag is
// needed here beyond matching their existing pattern.
$canSeeWithdrawalPolicyTab = Session::hasRole(['admin', 'treasurer', 'viewer', 'chairman', 'system_admin']);
$canSeeUserMgmtTab   = Session::hasRole(['admin', 'system_admin']); // Office Administrator's access here was explicitly revoked
$canSeeTechnicalTabs = Session::hasRole(['admin', 'system_admin']);
$tabs = [];
if ($canSeePolicyTabs) {
    $tabs['settings-general']         = ['icon' => 'bi-gear',               'label' => 'General'];
    // SA-1: points at the canonical FinancialYearController destination now
    // (financial-years), not the dead settings-financial-years redirect
    // stub -- consolidates what used to be a duplicate menu entry.
    $tabs['financial-years']          = ['icon' => 'bi-calendar-range',     'label' => 'Financial Years'];
    $tabs['settings-loans']           = ['icon' => 'bi-bank2',              'label' => 'Loans'];
    $tabs['settings-withdrawals']     = ['icon' => 'bi-box-arrow-up-right', 'label' => 'Withdrawals & Shares'];
    $tabs['settings-receipts']        = ['icon' => 'bi-receipt',            'label' => 'Receipts'];
}
if ($canSeeWithdrawalPolicyTab) {
    $tabs['withdrawal-policies'] = ['icon' => 'bi-sliders', 'label' => 'Withdrawal Policies'];
}
if ($canSeeUserMgmtTab) {
    $tabs['settings-users'] = ['icon' => 'bi-person-gear', 'label' => 'Users'];
}
if ($canSeeTechnicalTabs) {
    $tabs['settings-database'] = ['icon' => 'bi-database',     'label' => 'Database'];
    $tabs['settings-audit']    = ['icon' => 'bi-journal-text', 'label' => 'Audit Logs'];
}
?>
<ul class="nav nav-pills flex-wrap gap-2 mb-4">
    <?php foreach ($tabs as $page => $tab): ?>
    <li class="nav-item">
        <a class="nav-link d-flex align-items-center gap-1 <?= $currentPage === $page ? 'active' : '' ?>"
           href="<?= APP_URL ?>/index.php?page=<?= $page ?>">
            <i class="bi <?= $tab['icon'] ?>"></i>
            <span class="d-none d-md-inline"><?= $tab['label'] ?></span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
