<?php
/**
 * Cashier-specific sidebar — "collect and record money," per explicit user
 * design (2026-09 Cashier audit). Included by sidebar.php ONLY when
 * $userRole === 'cashier'; PHP include() shares the parent scope, so every
 * $canSeeXxx / $xxxOpen flag computed at the top of sidebar.php is already
 * available here unchanged -- this file adds no new backend grant and
 * removes none; it only reorganizes cashier's existing, already-role-gated
 * destinations under a COLLECTIONS-first IA instead of the generic shared
 * layout.
 *
 * Deliberately narrower than the backend allows, by design, not oversight:
 * cashier's backend access already reaches every ReportController-tier
 * report (Aging/Shares/Loan/Repayment/Withdrawal/Financial Summary) and the
 * base Reports/Weekly/Statement controllers, but only Savings/Repayment/
 * Fees/Withdrawal/Statements/Weekly reports are linked here -- the
 * "distinguish viewing a report from managing the accounting behind it"
 * principle means Aging/Shares/Loan Reports/Financial Summary and the
 * entire "Financial Reports" (Trial Balance/Income Statement/Balance
 * Sheet) section are left off this sidebar on purpose, still reachable by
 * direct URL if a specific reconciliation task ever needs one. No
 * Accounting/Expenses/Other Income/Vouchers/Investments/Administration
 * section exists here because cashier's backend access to all of those is
 * genuinely zero (verified in the Cashier audit) -- not a link
 * deliberately hidden, a capability that doesn't exist.
 *
 * "Mark Paid" is not a separate sidebar link: it has no navigable page of
 * its own (FeeController::markPaid() is a POST-only redirect action,
 * triggered by a modal on the Fee Charges/Charge Ledger page) -- exactly
 * the same as it is for every other role. Building a fake link to it
 * would violate "do not create placeholder routes."
 */
$cashierLoansOpen = $loansOpen; // reuses the existing $loanPages-derived flag (covers repayment pages too)
$cashierFeesOpen  = in_array($currentPage, ['fee-charges', 'fee-charge-form'], true);
?>
<!-- ── COLLECTIONS ────────────────────────────────────────── -->
<div class="sb-sidenav-menu-heading">Collections</div>

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
        <?php if ($canRecordDeposit): ?>
        <a class="nav-link <?= isActive('savings-add') ?>" href="<?= APP_URL ?>/index.php?page=savings-add">
            <i class="bi bi-plus-circle me-2"></i> Record Deposit
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

<?php if ($canSeeRepayments): ?>
<a class="nav-link <?= $cashierLoansOpen ? '' : 'collapsed' ?>"
   href="#cashierRepaymentsMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $cashierLoansOpen ? 'true' : 'false' ?>"
   aria-controls="cashierRepaymentsMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-arrow-down-circle"></i></div>
    Loan Repayments
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $cashierLoansOpen ? 'show' : '' ?>" id="cashierRepaymentsMenu" data-bs-parent="#sidenavAccordion">
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

<?php if ($canSeeFees): ?>
<a class="nav-link <?= $cashierFeesOpen ? '' : 'collapsed' ?>"
   href="#cashierFeesMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $cashierFeesOpen ? 'true' : 'false' ?>"
   aria-controls="cashierFeesMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-cash-coin"></i></div>
    Fees & Charges
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $cashierFeesOpen ? 'show' : '' ?>" id="cashierFeesMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <a class="nav-link <?= isActive('fee-charges') ?>" href="<?= APP_URL ?>/index.php?page=fee-charges">
            <i class="bi bi-list-ul me-2"></i> Charge Ledger
        </a>
        <?php if ($canCollectFee): ?>
        <a class="nav-link <?= isActive('fee-charge-form') ?>" href="<?= APP_URL ?>/index.php?page=fee-charge-form">
            <i class="bi bi-plus-circle me-2"></i> Record Fee
        </a>
        <?php endif; ?>
        <!-- "Mark Paid" is an inline modal action on Charge Ledger, not a
             separate page -- no link to build here, same as every other role. -->
    </nav>
</div>
<?php endif; ?>

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
        <a class="nav-link <?= isActive('withdrawals') ?>" href="<?= APP_URL ?>/index.php?page=withdrawals">
            <i class="bi bi-list-ul me-2"></i> Withdrawal Register
        </a>
        <?php if ($canProcessWithdrawal): ?>
        <a class="nav-link <?= isActive('withdrawal-process') ?>" href="<?= APP_URL ?>/index.php?page=withdrawal-process">
            <i class="bi bi-plus-circle me-2"></i> Process Withdrawal
        </a>
        <?php endif; ?>
        <!-- No Reverse link here on purpose: WithdrawalController::requireWriteAccess()
             (delete()/reversal) is admin/treasurer/chairman only -- cashier
             genuinely cannot reverse a withdrawal, this isn't UI-hidden. -->
    </nav>
</div>
<?php endif; ?>

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

<!-- ── MEMBERS ────────────────────────────────────────────── -->
<?php if ($canSeeMembers): ?>
<div class="sb-sidenav-menu-heading">Members</div>
<a class="nav-link <?= isActive('members') ?>"
   href="<?= APP_URL ?>/index.php?page=members">
    <div class="sb-nav-link-icon"><i class="bi bi-people"></i></div>
    Members
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
        <a class="nav-link <?= isActive('report-savings') ?>" href="<?= APP_URL ?>/index.php?page=report-savings">
            <i class="bi bi-piggy-bank me-2"></i> Savings Report
        </a>
        <?php if ($canSeeFinancialReports): ?>
        <a class="nav-link <?= isActive('report-repayments') ?>" href="<?= APP_URL ?>/index.php?page=report-repayments">
            <i class="bi bi-arrow-down-circle me-2"></i> Repayment Reports
        </a>
        <a class="nav-link <?= isActive('report-withdrawals') ?>" href="<?= APP_URL ?>/index.php?page=report-withdrawals">
            <i class="bi bi-box-arrow-up-right me-2"></i> Withdrawal Reports
        </a>
        <?php endif; ?>
        <a class="nav-link <?= isActive('fee-report') ?>" href="<?= APP_URL ?>/index.php?page=fee-report">
            <i class="bi bi-cash-coin me-2"></i> Fees Report
        </a>
        <?php if ($canSeeStatements): ?>
        <a class="nav-link <?= isActive('statements') ?>" href="<?= APP_URL ?>/index.php?page=statements">
            <i class="bi bi-file-person-fill me-2"></i> Member Statements
        </a>
        <?php endif; ?>

        <div class="sb-sidenav-menu-heading" style="padding-left:2.5rem;">Weekly Reports</div>
        <a class="nav-link <?= isActive('weekly-savings') ?>" href="<?= APP_URL ?>/index.php?page=weekly-savings">
            <i class="bi bi-piggy-bank me-2"></i> Savings Deposits
        </a>
        <a class="nav-link <?= isActive('weekly-repayments') ?>" href="<?= APP_URL ?>/index.php?page=weekly-repayments">
            <i class="bi bi-arrow-down-circle me-2"></i> Loan Repayments
        </a>
    </nav>
</div>
<?php endif; ?>

<!-- ── OTHER ──────────────────────────────────────────────── -->
<?php if ($canSeeReferrals): ?>
<div class="sb-sidenav-menu-heading">Other</div>
<a class="nav-link <?= isActiveGroup(['referral-bonuses','referral-bonus-create','referral-bonus-view','referral-bonus-report']) ?>"
   href="<?= APP_URL ?>/index.php?page=referral-bonuses">
    <div class="sb-nav-link-icon"><i class="bi bi-person-hearts"></i></div>
    Referral Bonuses
</a>
<?php endif; ?>
