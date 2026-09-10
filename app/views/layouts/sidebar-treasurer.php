<?php
/**
 * Treasurer-specific sidebar — a deliberately distinct finance-control IA,
 * per explicit user redesign (2026-09). Included by sidebar.php ONLY when
 * $userRole === 'treasurer'; PHP include() shares the parent scope, so
 * every $canSeeXxx / $xxxOpen flag computed at the top of sidebar.php is
 * already available here unchanged -- this file adds no new backend
 * grants, it only reorganizes existing, already-role-gated destinations
 * into groupings that read as "oversee & control finances" rather than
 * "do everything involving money."
 *
 * Two links the user asked for are deliberately NOT included because no
 * real destination exists for them anywhere in the codebase yet:
 *   - "Reconciliation" -- confirmed in an earlier audit (Stage 25/26) that
 *     no live reconciliation feature exists, only forensic/audit scripts.
 *   - "Cash Flow" statement -- no such report has been built; only
 *     Trial Balance / Income Statement / Balance Sheet exist.
 * A generic "Audit Trail" link is also omitted: the only audit-log page
 * that exists (Settings > Audit Logs) is deliberately admin/system_admin
 * only (technical/security log, not a financial one) -- adding Treasurer
 * there would be a real new backend grant, not a sidebar reorganization,
 * so it's flagged rather than silently added. Per-record audit trails
 * (who posted/approved/reversed) already show on each voucher/adjustment/
 * loan's own detail page.
 * "Loan Balances" and "Journal Entries" are not separate links either --
 * they'd duplicate Loan Register/Loan Reports and General Ledger
 * respectively (report-general-ledger with no account_id already lists
 * every journal entry), so no new link was added for them.
 */
$treasurerCashBankOpen = $currentPage === 'report-general-ledger' && in_array((int)($_GET['account_id'] ?? 0), [7, 8, 10], true);
$treasurerGLAccountId  = (int)($_GET['account_id'] ?? 0);
?>
<!-- ── MEMBERSHIP (view-only) ─────────────────────────────── -->
<?php if ($canSeeMembers): ?>
<div class="sb-sidenav-menu-heading">Membership</div>
<a class="nav-link <?= isActive('members') ?>"
   href="<?= APP_URL ?>/index.php?page=members">
    <div class="sb-nav-link-icon"><i class="bi bi-people"></i></div>
    Members
</a>
<?php endif; ?>

<!-- ── FINANCIAL OPERATIONS ───────────────────────────────── -->
<div class="sb-sidenav-menu-heading">Financial Operations</div>

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
    </nav>
</div>
<?php endif; ?>

<?php if ($canSeeLoansSection): ?>
<a class="nav-link <?= $loansOpen ? '' : 'collapsed' ?>"
   href="#loansMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $loansOpen ? 'true' : 'false' ?>"
   aria-controls="loansMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-bank2"></i></div>
    Loans
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $loansOpen ? 'show' : '' ?>" id="loansMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <?php if ($canSeeLoans): ?>
        <a class="nav-link <?= isActive('loans') ?>" href="<?= APP_URL ?>/index.php?page=loans">
            <i class="bi bi-list-ul me-2"></i> Loan Register
        </a>
        <?php endif; ?>
        <?php if ($canSeeRepayments): ?>
        <a class="nav-link <?= isActive('repayments') ?>" href="<?= APP_URL ?>/index.php?page=repayments">
            <i class="bi bi-arrow-down-circle me-2"></i> Loan Repayments
        </a>
        <?php endif; ?>
        <?php if ($canAddRepayment): ?>
        <a class="nav-link <?= isActive('repayment-add') ?>" href="<?= APP_URL ?>/index.php?page=repayment-add">
            <i class="bi bi-plus-circle me-2"></i> Record Repayment
        </a>
        <?php endif; ?>
        <!-- No loan-origination link here on purpose -- creating a new loan
             is now the Loans Officer's workflow (LoanController::requireOriginateAccess). -->
    </nav>
</div>
<?php endif; ?>

<?php if ($canSeeFees):
    $treasurerFeesOpen = in_array($currentPage, ['fee-charges', 'fee-charge-form'], true);
?>
<a class="nav-link <?= $treasurerFeesOpen ? '' : 'collapsed' ?>"
   href="#treasurerFeesMenu" data-bs-toggle="collapse"
   aria-expanded="<?= $treasurerFeesOpen ? 'true' : 'false' ?>"
   aria-controls="treasurerFeesMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-cash-coin"></i></div>
    Fees & Charges
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= $treasurerFeesOpen ? 'show' : '' ?>" id="treasurerFeesMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <a class="nav-link <?= isActive('fee-charges') ?>" href="<?= APP_URL ?>/index.php?page=fee-charges">
            <i class="bi bi-list-ul me-2"></i> Fee Register
        </a>
        <a class="nav-link <?= isActive('fee-charge-form') ?>" href="<?= APP_URL ?>/index.php?page=fee-charge-form">
            <i class="bi bi-plus-circle me-2"></i> Record Fee
        </a>
    </nav>
</div>
<?php endif; ?>

<!-- ── FINANCE ────────────────────────────────────────────── -->
<?php if ($canSeeFinanceSection || $canSeeInvestments): ?>
<div class="sb-sidenav-menu-heading">Finance</div>

<a class="nav-link <?= ($financeOpen || $treasurerCashBankOpen) ? '' : 'collapsed' ?>"
   href="#financeMenu" data-bs-toggle="collapse"
   aria-expanded="<?= ($financeOpen || $treasurerCashBankOpen) ? 'true' : 'false' ?>"
   aria-controls="financeMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-wallet2"></i></div>
    Finance
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= ($financeOpen || $treasurerCashBankOpen) ? 'show' : '' ?>" id="financeMenu" data-bs-parent="#sidenavAccordion">
    <nav class="sb-sidenav-menu-nested nav">
        <div class="sb-sidenav-menu-heading" style="padding-left:2.5rem;">Cash & Bank</div>
        <a class="nav-link <?= $treasurerGLAccountId === 7 ? 'active' : '' ?>"
           href="<?= APP_URL ?>/index.php?page=report-general-ledger&account_id=7">
            <i class="bi bi-cash me-2"></i> Cash at Hand
        </a>
        <a class="nav-link <?= $treasurerGLAccountId === 8 ? 'active' : '' ?>"
           href="<?= APP_URL ?>/index.php?page=report-general-ledger&account_id=8">
            <i class="bi bi-phone me-2"></i> Mobile Money / Float
        </a>
        <a class="nav-link <?= $treasurerGLAccountId === 10 ? 'active' : '' ?>"
           href="<?= APP_URL ?>/index.php?page=report-general-ledger&account_id=10">
            <i class="bi bi-bank me-2"></i> Bank Accounts
        </a>

        <div class="sb-sidenav-menu-heading" style="padding-left:2.5rem;">Financial Transactions</div>
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
        <a class="nav-link <?= isActive('reports') ?>" href="<?= APP_URL ?>/index.php?page=reports">
            <i class="bi bi-speedometer2 me-2"></i> Reports Dashboard
        </a>
        <?php if ($canSeeFinancialReports): ?>
        <a class="nav-link <?= isActive('report-financial') ?>" href="<?= APP_URL ?>/index.php?page=report-financial">
            <i class="bi bi-clipboard-data me-2"></i> Financial Summary
        </a>
        <?php endif; ?>
        <a class="nav-link <?= isActive('report-savings') ?>" href="<?= APP_URL ?>/index.php?page=report-savings">
            <i class="bi bi-piggy-bank me-2"></i> Savings Report
        </a>
        <?php if ($canSeeFinancialReports): ?>
        <a class="nav-link <?= isActive('report-loans') ?>" href="<?= APP_URL ?>/index.php?page=report-loans">
            <i class="bi bi-bank2 me-2"></i> Loan Reports
        </a>
        <a class="nav-link <?= isActive('report-repayments') ?>" href="<?= APP_URL ?>/index.php?page=report-repayments">
            <i class="bi bi-arrow-down-circle me-2"></i> Repayment Reports
        </a>
        <a class="nav-link <?= isActive('report-loan-aging') ?>" href="<?= APP_URL ?>/index.php?page=report-loan-aging">
            <i class="bi bi-hourglass-split me-2"></i> Aging Report
        </a>
        <a class="nav-link <?= isActive('report-shares') ?>" href="<?= APP_URL ?>/index.php?page=report-shares">
            <i class="bi bi-pie-chart me-2"></i> Shares Report
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

        <?php if ($canSeeFinancialReports): ?>
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

<!-- ── ACCOUNTING ─────────────────────────────────────────── -->
<?php if ($canSeeAccountingSection || $canSeeAccountingStatements): ?>
<div class="sb-sidenav-menu-heading">Accounting</div>

<a class="nav-link <?= ($accountingOpen || $financialReportsOpen) ? '' : 'collapsed' ?>"
   href="#accountingMenu" data-bs-toggle="collapse"
   aria-expanded="<?= ($accountingOpen || $financialReportsOpen) ? 'true' : 'false' ?>"
   aria-controls="accountingMenu">
    <div class="sb-nav-link-icon"><i class="bi bi-journal-bookmark-fill"></i></div>
    Accounting
    <div class="sb-sidenav-collapse-arrow ms-auto"><i class="bi bi-chevron-down"></i></div>
</a>
<div class="collapse <?= ($accountingOpen || $financialReportsOpen) ? 'show' : '' ?>" id="accountingMenu" data-bs-parent="#sidenavAccordion">
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
        <a class="nav-link <?= isActiveGroup(['withdrawal-policies','withdrawal-policy-history','withdrawal-policy-create','withdrawal-policy-edit-draft']) ?>" href="<?= APP_URL ?>/index.php?page=withdrawal-policies">
            <i class="bi bi-sliders me-2"></i> Withdrawal Policies
        </a>
        <?php endif; ?>
        <?php if ($canSeeExpenseCategories): ?>
        <a class="nav-link <?= isActive('expense-categories') ?>" href="<?= APP_URL ?>/index.php?page=expense-categories">
            <i class="bi bi-tags me-2"></i> Expense Categories
        </a>
        <?php endif; ?>
        <?php if ($canSeeOtherIncomeCategories): ?>
        <a class="nav-link <?= isActive('other-income-categories') ?>" href="<?= APP_URL ?>/index.php?page=other-income-categories">
            <i class="bi bi-tags me-2"></i> Other Income Categories
        </a>
        <?php endif; ?>
    </nav>
</div>
<?php endif; ?>
