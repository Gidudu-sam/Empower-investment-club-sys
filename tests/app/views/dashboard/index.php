<?php $userName = htmlspecialchars(Session::get('user_name', 'User'));
// Loan-related cards/tables on this shared dashboard are hidden from
// Office Administrator -- same "not their workspace" reasoning already
// applied to the Reports Dashboard.
$canSeeLoanWidgets = !Session::hasRole(['office_admin']);
$isTreasurer = Session::hasRole(['treasurer']);
// Follow-up/reminder-style widgets (due-soon and overdue nudges) are
// operational collection tools for Office Admin/Loans Officer -- Treasurer
// is financial control, not collections, so these are hidden for both
// roles. Treasurer still keeps the portfolio figure itself (Outstanding
// Loans) and the Recent Loans/Repayments records, just not the "go chase
// this" prompts.
$canSeeLoanFollowUp = !Session::hasRole(['office_admin', 'treasurer']);
// Member follow-up widgets (active/inactive headcounts, who-hasn't-saved
// nudges) belong to Office Admin's member-facing workspace -- Treasurer
// doesn't run member collections, so these are Treasurer-only exclusions
// (Office Admin, Cashier, Loans Officer, Chairman, admin all keep them).
$canSeeMemberFollowUp = !$isTreasurer;
// Chairman dashboard redesign (2026-09): governance/oversight, not
// operational collection activity -- these three follow-up widgets stay
// hidden for Chairman specifically (Active/Inactive Members and Overdue
// Loans are untouched, Chairman keeps those).
//
// Stage 23: Vice Chairman shares this exact governance-shaped dashboard
// with Chairman -- deliberately kept as one boolean (not a second,
// separately-tracked flag) so every one of this variable's ~13 existing
// usages below (layout widths, which widgets show/hide, the Action
// Required panel) applies identically to both roles without having to
// re-audit each usage individually. Only the page heading below
// distinguishes the two by name.
$isChairman = Session::hasRole(['chairman', 'vice_chairman']);

if (!function_exists('waNumber')) {
    /**
     * Normalize a Ugandan phone number (various messy formats seen in
     * member records) into wa.me's expected digits-only international form.
     * Takes the first recognizable number if the field has more than one.
     */
    function waNumber(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') return '';
        if (str_starts_with($digits, '256')) return substr($digits, 0, 12);
        if (str_starts_with($digits, '0'))   return '256' . substr($digits, 1, 9);
        return '256' . substr($digits, 0, 9);
    }
}
?>

<!-- ── Page heading ─────────────────────────────────────────── -->
<!-- Stacks vertically on mobile (no room for the title block and the
     date badge side by side without crowding/overlapping them -- unlike
     layouts/page-title.php's shared header, this one is hand-rolled and
     was missing flex-wrap entirely), sits side by side again from the
     sm breakpoint up, matching the original desktop appearance exactly. -->
<div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-sm-between gap-2 mb-3">
    <div>
        <?php if ($isChairman): ?>
        <h1 class="h4 mb-1 fw-bold text-gray-800" style="font-family:'Space Grotesk',sans-serif;">
            <?= Session::hasRole(['vice_chairman']) ? "Vice Chairman's Overview" : "Chairman's Overview" ?>
        </h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">Governance, approvals and club performance at a glance.</p>
        <?php else: ?>
        <h1 class="h4 mb-1 fw-bold text-gray-800" style="font-family:'Space Grotesk',sans-serif;">
            Dashboard
        </h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">Welcome back, <strong><?= $userName ?></strong></p>
        <?php endif; ?>
    </div>
    <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-2" style="font-size:.7rem;">
        <i class="bi bi-calendar3 me-1"></i><?= date('l, d F Y') ?>
    </span>
</div>

<?php if ($isChairman && $chairmanOverview !== null): ?>
<!-- ── ACTION REQUIRED (Chairman only) ─────────────────────────── -->
<div id="pending-approvals" class="card mb-3" style="border-left:4px solid <?= $chairmanOverview['totalPending'] > 0 ? 'var(--gold-deep, #c99a2e)' : 'var(--brand-navy, #0d3b66)' ?>;">
    <div class="card-header d-flex align-items-center justify-content-between py-2">
        <h6 class="mb-0 fw-semibold small">
            <?php if ($chairmanOverview['totalPending'] > 0): ?>
            <i class="bi bi-bell-fill me-2 text-warning"></i>Action Required
            <span class="badge bg-warning text-dark rounded-pill ms-1"><?= $chairmanOverview['totalPending'] ?></span>
            <?php else: ?>
            <i class="bi bi-check2-circle me-2 text-success"></i>Action Required
            <?php endif; ?>
        </h6>
        <span class="text-muted small">What needs my decision?</span>
    </div>
    <div class="card-body">
        <?php if ($chairmanOverview['totalPending'] === 0): ?>
        <div class="text-center text-muted py-3 small">
            <i class="bi bi-check-circle fs-2 d-block mb-2 opacity-25"></i>
            Nothing awaiting your approval right now.
        </div>
        <?php else: ?>
        <div class="row g-2 mb-3">
            <?php
            $categoryMeta = [
                'vouchers'         => ['label' => 'Vouchers',         'icon' => 'bi-journal-check',   'page' => 'internal-vouchers'],
                'loans'            => ['label' => 'Loans',            'icon' => 'bi-bank2',            'page' => 'loans&status=pending_approval'],
                'adjustments'      => ['label' => 'Adjustments',      'icon' => 'bi-sliders',          'page' => 'member-adjustments'],
                'opening_balances' => ['label' => 'Opening Balances', 'icon' => 'bi-clipboard2-check', 'page' => 'opening-balances'],
                'investments'      => ['label' => 'Investments',      'icon' => 'bi-graph-up',         'page' => 'investments'],
                'loan_applications'=> ['label' => 'Applications',     'icon' => 'bi-file-earmark-text', 'page' => 'loan-applications'],
            ];
            foreach ($categoryMeta as $key => $meta):
                $count = $chairmanOverview['pendingCounts'][$key] ?? 0;
            ?>
            <div class="col-6 col-md-2dot4" style="flex:1 1 18%;min-width:140px;">
                <a href="<?= APP_URL ?>/index.php?page=<?= $meta['page'] ?>" class="text-decoration-none">
                    <div class="border rounded-3 p-2 text-center h-100 <?= $count > 0 ? 'border-warning bg-warning bg-opacity-10' : '' ?>">
                        <div class="small text-muted"><i class="bi <?= $meta['icon'] ?> me-1"></i><?= $meta['label'] ?></div>
                        <div class="fw-bold fs-5 <?= $count > 0 ? 'text-warning' : 'text-muted' ?>"><?= $count ?></div>
                        <div class="small text-primary">Review <i class="bi bi-arrow-right"></i></div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="ps-3">Reference</th>
                        <th>Type</th>
                        <th class="text-end">Amount</th>
                        <th>Created By</th>
                        <th>Submitted</th>
                        <th class="text-end pe-3">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingApprovalItems as $item): ?>
                    <tr>
                        <td class="ps-3 fw-semibold"><?= htmlspecialchars($item['reference']) ?></td>
                        <td><span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($item['type']) ?></span></td>
                        <td class="text-end">Shs <?= number_format($item['amount'], 2) ?></td>
                        <td class="small"><?= htmlspecialchars($item['created_by']) ?></td>
                        <td class="small text-muted"><?= $item['submitted_at'] ? date('d M Y', strtotime($item['submitted_at'])) : '—' ?></td>
                        <td class="text-end pe-3"><a href="<?= $item['review_url'] ?>" class="btn btn-sm btn-outline-primary">Review</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Pending Approvals (admin only — Chairman gets the redesigned Action Required panel above) ── -->
<?php if ($pendingApprovals !== null && !$isChairman): $pendingCount = count($pendingApprovalItems); ?>
<div class="card mb-3" style="border-left:4px solid var(--brand-navy, #0d3b66);">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="bi bi-check2-square me-2"></i>Pending Approvals
            <?php if ($pendingCount > 0): ?>
                <span class="badge bg-primary rounded-pill ms-1"><?= $pendingCount ?></span>
            <?php endif; ?>
        </h6>
        <span class="text-muted small">What requires my attention?</span>
    </div>
    <div class="card-body p-0">
        <?php if ($pendingCount === 0): ?>
            <div class="text-center text-muted py-4 small">
                <i class="bi bi-check-circle fs-2 d-block mb-2 opacity-25"></i>
                Nothing awaiting your approval right now.
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="ps-3">Reference</th>
                        <th>Type</th>
                        <th class="text-end">Amount</th>
                        <th>Created By</th>
                        <th>Submitted</th>
                        <th class="text-end pe-3">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingApprovalItems as $item): ?>
                    <tr>
                        <td class="ps-3 fw-semibold"><?= htmlspecialchars($item['reference']) ?></td>
                        <td><span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($item['type']) ?></span></td>
                        <td class="text-end">Shs <?= number_format($item['amount'], 2) ?></td>
                        <td class="small"><?= htmlspecialchars($item['created_by']) ?></td>
                        <td class="small text-muted"><?= $item['submitted_at'] ? date('d M Y', strtotime($item['submitted_at'])) : '—' ?></td>
                        <td class="text-end pe-3"><a href="<?= $item['review_url'] ?>" class="btn btn-sm btn-outline-primary">Review</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Loan alerts ───────────────────────────────────────────── -->
<?php if ($canSeeLoanFollowUp && !empty($loanAlerts)): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 mb-4" role="alert" style="border:1px solid var(--gold-soft);background:var(--gold-soft);color:var(--gold-deep);">
    <i class="bi bi-bell-fill flex-shrink-0 mt-1"></i>
    <div class="w-100">
        <strong><?= count($loanAlerts) ?> Loan Alert<?= count($loanAlerts)!==1?'s':'' ?></strong>
        <ul class="mb-0 mt-1 small">
            <?php foreach ($loanAlerts as $a):
                $d = (int)$a['days_remaining'];
                if ($d < 0)      $msg = "overdue by " . abs($d) . " day" . (abs($d)!==1?'s':'');
                elseif($d === 0) $msg = "due TODAY";
                else             $msg = "due in {$d} day" . ($d!==1?'s':'');
            ?>
            <li>
                <a href="<?= APP_URL ?>/index.php?page=loan-view&id=<?= $a['id'] ?>"
                   class="fw-semibold text-decoration-none" style="color:var(--gold-deep);">
                    <?= htmlspecialchars($a['loan_number']) ?>
                </a>
                — <?= htmlspecialchars($a['first_name'].' '.$a['last_name']) ?>
                — <?= $msg ?>
                (Outstanding: Shs <?= number_format($a['outstanding'],2) ?>)
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <a href="<?= APP_URL ?>/index.php?page=loans&filter=overdue"
       class="btn btn-sm ms-auto text-nowrap" style="background:var(--gold-deep);color:#fff;border:none;">View All</a>
</div>
<?php endif; ?>

<!-- ── ROW 1: Stat cards ───────────────────────────────────────── -->
<div class="row g-3 mb-3">

    <div class="<?= $isChairman ? 'col-xl-4' : ($canSeeLoanWidgets ? 'col-xl-3' : 'col-xl-4') ?> col-6 col-lg-4">
        <a href="<?= APP_URL ?>/index.php?page=savings" class="text-decoration-none">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Total Club Savings</span>
                    <span class="stat-dot stat-dot-green"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><span style="font-size:.75rem;color:var(--slate-soft);font-weight:500;">Shs</span> <?= number_format($totalSavings, 0) ?></div>
                <div class="stat-sub">All members, all time</div>
            </div>
        </a>
    </div>

    <?php
    // Navigation fix (Shares Module Stage 1): this card previously linked to
    // ?page=withdrawals -- a copy-paste artifact, not the Shares page. It now
    // points to the new Shares workspace (?page=shares), gated to the same
    // role set as ShareController itself (mirrors report-shares' verified
    // effective viewers) so the card never dead-ends into a 403 -- a viewer
    // without Shares access still sees the same total, just not as a link.
    $canSeeShares = Session::hasRole(['admin', 'treasurer', 'cashier', 'viewer', 'chairman', 'secretary', 'vice_chairman']);
    ?>
    <div class="<?= $isChairman ? 'col-xl-4' : ($canSeeLoanWidgets ? 'col-xl-3' : 'col-xl-4') ?> col-6 col-lg-4">
        <?php if ($canSeeShares): ?>
        <a href="<?= APP_URL ?>/index.php?page=shares" class="text-decoration-none">
        <?php endif; ?>
            <div class="stat-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Total Shares</span>
                    <span class="stat-dot stat-dot-green"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><span style="font-size:.75rem;color:var(--slate-soft);font-weight:500;">Shs</span> <?= number_format($totalShares, 0) ?></div>
                <div class="stat-sub">Retained share capital</div>
            </div>
        <?php if ($canSeeShares): ?>
        </a>
        <?php endif; ?>
    </div>

    <?php if ($canSeeMemberFollowUp): ?>
    <div class="<?= $isChairman ? 'col-xl-4' : ($canSeeLoanWidgets ? 'col-xl-3' : 'col-xl-4') ?> col-6 col-lg-4">
        <a href="<?= APP_URL ?>/index.php?page=members&status=active" class="text-decoration-none">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Active Members</span>
                    <span class="stat-dot stat-dot-green"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><?= number_format($activeMembers) ?></div>
                <div class="stat-sub">of <?= number_format($totalMembers) ?> total members</div>
            </div>
        </a>
    </div>

    <div class="<?= $isChairman ? 'col-xl-4' : ($canSeeLoanWidgets ? 'col-xl-3' : 'col-xl-4') ?> col-6 col-lg-4">
        <a href="<?= APP_URL ?>/index.php?page=members&status=dormant" class="text-decoration-none">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Inactive Members</span>
                    <span class="stat-dot stat-dot-muted"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><?= number_format($inactiveMembers + $dormantMembers) ?></div>
                <div class="stat-sub"><?= number_format($inactiveMembers) ?> inactive · <?= number_format($dormantMembers) ?> dormant</div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($canSeeLoanWidgets): ?>
    <div class="<?= $isChairman ? 'col-xl-4' : 'col-xl-3' ?> col-6 col-lg-4">
        <a href="<?= APP_URL ?>/index.php?page=loans&status=active" class="text-decoration-none">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Outstanding Loans</span>
                    <span class="stat-dot stat-dot-gold"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><span style="font-size:.75rem;color:var(--slate-soft);font-weight:500;">Shs</span> <?= number_format($loanOutstanding, 0) ?></div>
                <div class="stat-sub"><?= number_format($activeLoans) ?> active loan<?= $activeLoans !== 1 ? 's' : '' ?></div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <?php if (!$isChairman): ?>
    <div class="<?= $canSeeLoanWidgets ? 'col-xl-3' : 'col-xl-4' ?> col-6 col-lg-4">
        <a href="<?= APP_URL ?>/index.php?page=savings" class="text-decoration-none">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">This Week's Savings Collections</span>
                    <span class="stat-dot stat-dot-green"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><span style="font-size:.75rem;color:var(--slate-soft);font-weight:500;">Shs</span> <?= number_format($weekSavingsCollections['total'], 0) ?></div>
                <div class="stat-sub"><?= $weekSavingsCollections['start']->format('D g:i A') ?> – <?= $weekSavingsCollections['end']->format('D g:i A') ?></div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($canSeeMemberFollowUp && !$isChairman): ?>
    <div class="<?= $canSeeLoanWidgets ? 'col-xl-3' : 'col-xl-4' ?> col-6 col-lg-4">
        <a href="javascript:void(0)" data-bs-toggle="modal" data-bs-target="#notSavedModal" class="text-decoration-none">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Not Saved This Week</span>
                    <span class="stat-dot <?= count($notSavedMembers) > 0 ? 'stat-dot-rust' : 'stat-dot-muted' ?>"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><?= number_format(count($notSavedMembers)) ?></div>
                <div class="stat-sub">Active members · tap to view &amp; remind</div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($canSeeLoanFollowUp && !$isChairman): ?>
    <div class="col-xl-3 col-6 col-lg-4">
        <a href="javascript:void(0)" data-bs-toggle="modal" data-bs-target="#dueWeekLoansModal" class="text-decoration-none">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Loans Due This Week</span>
                    <span class="stat-dot <?= $dueWeekLoans > 0 ? 'stat-dot-gold' : 'stat-dot-muted' ?>"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><?= number_format($dueWeekLoans) ?></div>
                <div class="stat-sub">Due today: <?= $dueTodayLoans ?> · tap to view &amp; remind</div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($canSeeLoanFollowUp || $isChairman): ?>
    <div class="<?= $isChairman ? 'col-xl-4' : 'col-xl-3' ?> col-6 col-lg-4">
        <a href="javascript:void(0)" data-bs-toggle="modal" data-bs-target="#overdueLoansModal" class="text-decoration-none">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Overdue Loans</span>
                    <span class="stat-dot <?= $overdueLoans > 0 ? 'stat-dot-rust' : 'stat-dot-muted' ?>"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><?= number_format($overdueLoans) ?></div>
                <div class="stat-sub">Needs follow-up · tap to view &amp; remind</div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($isTreasurer && $treasurerFinancials): ?>
    <div class="col-xl-3 col-6 col-lg-4">
        <a href="<?= APP_URL ?>/index.php?page=report-general-ledger&account_id=7" class="text-decoration-none">
            <div class="stat-card stat-card-info h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Cash &amp; Bank Balance</span>
                    <span class="stat-dot stat-dot-green"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><span style="font-size:.75rem;color:var(--slate-soft);font-weight:500;">Shs</span> <?= number_format($treasurerFinancials['cashBankBalance'], 0) ?></div>
                <div class="stat-sub">Cash at Hand + Mobile Money + Bank</div>
            </div>
        </a>
    </div>

    <div class="col-xl-3 col-6 col-lg-4">
        <a href="<?= APP_URL ?>/index.php?page=report-income-statement" class="text-decoration-none">
            <div class="stat-card stat-card-success h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Total Income</span>
                    <span class="stat-dot stat-dot-green"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><span style="font-size:.75rem;color:var(--slate-soft);font-weight:500;">Shs</span> <?= number_format($treasurerFinancials['totalIncome'], 0) ?></div>
                <div class="stat-sub"><?= $treasurerFinancials['activeFinancialYear']['name'] ?? 'All time' ?></div>
            </div>
        </a>
    </div>

    <div class="col-xl-3 col-6 col-lg-4">
        <a href="<?= APP_URL ?>/index.php?page=report-income-statement" class="text-decoration-none">
            <div class="stat-card stat-card-warning h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Total Expenses</span>
                    <span class="stat-dot stat-dot-gold"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><span style="font-size:.75rem;color:var(--slate-soft);font-weight:500;">Shs</span> <?= number_format($treasurerFinancials['totalExpense'], 0) ?></div>
                <div class="stat-sub"><?= $treasurerFinancials['activeFinancialYear']['name'] ?? 'All time' ?></div>
            </div>
        </a>
    </div>

    <div class="col-xl-3 col-6 col-lg-4">
        <a href="<?= APP_URL ?>/index.php?page=report-income-statement" class="text-decoration-none">
            <div class="stat-card <?= $treasurerFinancials['netSurplus'] >= 0 ? 'stat-card-success' : 'stat-card-danger' ?> h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Net <?= $treasurerFinancials['netSurplus'] >= 0 ? 'Surplus' : 'Deficit' ?></span>
                    <span class="stat-dot <?= $treasurerFinancials['netSurplus'] >= 0 ? 'stat-dot-green' : 'stat-dot-rust' ?>"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><span style="font-size:.75rem;color:var(--slate-soft);font-weight:500;">Shs</span> <?= number_format(abs($treasurerFinancials['netSurplus']), 0) ?></div>
                <div class="stat-sub">Income − Expenses</div>
            </div>
        </a>
    </div>
    <?php endif; ?>

</div><!-- /.row stat cards -->

<?php if ($isChairman && $chairmanOverview !== null): ?>
<!-- ── Loan Portfolio + Club Health (Chairman only) ─────────────── -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-bank2 me-2"></i>Loan Portfolio</h6></div>
            <div class="card-body">
                <div class="d-flex justify-content-between py-1 border-bottom">
                    <span class="text-muted small">Active loans</span>
                    <span class="fw-bold"><?= number_format($chairmanOverview['loanPortfolio']['active']) ?></span>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom">
                    <span class="text-muted small">Pending approval</span>
                    <span class="fw-bold"><?= number_format($chairmanOverview['loanPortfolio']['pendingApproval']) ?></span>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom">
                    <span class="text-muted small">Overdue loans</span>
                    <span class="fw-bold <?= $chairmanOverview['loanPortfolio']['overdue'] > 0 ? 'text-danger' : '' ?>"><?= number_format($chairmanOverview['loanPortfolio']['overdue']) ?></span>
                </div>
                <div class="d-flex justify-content-between py-1 mb-2">
                    <span class="text-muted small">Outstanding</span>
                    <span class="fw-bold">Shs <?= number_format($chairmanOverview['loanPortfolio']['outstanding'], 0) ?></span>
                </div>
                <a href="<?= APP_URL ?>/index.php?page=loans" class="small text-decoration-none">View loan portfolio <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-heart-pulse me-2"></i>Club Health</h6></div>
            <div class="card-body">
                <?php
                $healthRow = function (string $label, string $status, string $detail) {
                    $dot = $status === 'good' ? 'bi-circle-fill text-success' : ($status === 'warning' ? 'bi-circle-fill text-warning' : 'bi-circle-fill text-muted');
                    echo '<div class="d-flex justify-content-between align-items-center py-1 border-bottom">'
                       . '<span class="small"><i class="bi ' . $dot . ' me-2" style="font-size:.5rem;"></i>' . htmlspecialchars($label) . '</span>'
                       . '<span class="small text-muted">' . htmlspecialchars($detail) . '</span></div>';
                };
                $healthRow('Loan Portfolio', $chairmanOverview['health']['loanPortfolio'], $chairmanOverview['loanPortfolio']['overdue'] . ' overdue');
                $healthRow('Approvals', $chairmanOverview['health']['approvals'], $chairmanOverview['totalPending'] . ' awaiting decision');
                $healthRow('Membership', $chairmanOverview['health']['membership'], number_format($activeMembers) . ' active / ' . number_format($totalMembers) . ' total');
                ?>
                <div class="d-flex justify-content-between align-items-center py-1">
                    <span class="small"><i class="bi bi-circle-fill text-primary me-2" style="font-size:.5rem;"></i>Accounting</span>
                    <a href="<?= APP_URL ?>/index.php?page=report-financial" class="small text-decoration-none">View financial position <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Recent Decisions + Recent Activity (Chairman only) ───────── -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-clock-history me-2"></i>Recent Decisions</h6></div>
            <div class="card-body p-0">
                <?php if (empty($chairmanOverview['recentDecisions'])): ?>
                <div class="text-center text-muted py-4 small">No governance decisions recorded yet.</div>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($chairmanOverview['recentDecisions'] as $d):
                        $icon = $d['action'] === 'rejected' ? 'bi-x-circle text-danger' : 'bi-check-circle text-success';
                    ?>
                    <li class="list-group-item small">
                        <i class="bi <?= $icon ?> me-2"></i>
                        <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $d['entity_type']))) ?> <?= htmlspecialchars($d['action']) ?>
                        <div class="text-muted" style="font-size:.72rem;">
                            <?= htmlspecialchars($d['user_name'] ?? 'Unknown') ?> · <?= date('d M Y, g:i A', strtotime($d['created_at'])) ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-activity me-2"></i>Recent Activity</h6></div>
            <div class="card-body p-0">
                <?php if (empty($chairmanOverview['recentActivity'])): ?>
                <div class="text-center text-muted py-4 small">No recent activity recorded yet.</div>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($chairmanOverview['recentActivity'] as $a): ?>
                    <li class="list-group-item small">
                        <?= htmlspecialchars($a['description'] ?: str_replace('_', ' ', $a['action'])) ?>
                        <div class="text-muted" style="font-size:.72rem;">
                            <?= htmlspecialchars($a['user_name'] ?? 'Unknown') ?> · <?= date('d M Y, g:i A', strtotime($a['created_at'])) ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isTreasurer && $treasurerFinancials): ?>
<!-- ── Today's Collections (Treasurer only) ─────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-calendar-check me-2"></i>Today's Collections</h6></div>
            <div class="card-body">
                <div class="row g-3 text-center">
                    <div class="col-6 col-md-3">
                        <div class="text-muted small">Savings</div>
                        <div class="fw-bold">Shs <?= number_format($treasurerFinancials['todaySavings'], 0) ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-muted small">Loan Repayments</div>
                        <div class="fw-bold">Shs <?= number_format($treasurerFinancials['todayRepaymentsTotal'], 0) ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-muted small">Fees</div>
                        <div class="fw-bold">Shs <?= number_format($treasurerFinancials['todayFees'], 0) ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-muted small">Withdrawals</div>
                        <div class="fw-bold">Shs <?= number_format($treasurerFinancials['todayWithdrawals'], 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Modal: Active members who haven't saved this week ──────── -->
<div class="modal fade" id="notSavedModal" tabindex="-1" aria-labelledby="notSavedModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="notSavedModalLabel">
                    <i class="bi bi-exclamation-circle-fill text-danger me-2"></i>Active Members — Not Saved This Week
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-3">Name</th>
                                <th>Account No.</th>
                                <th>Contact</th>
                                <th class="text-end">Last Saved</th>
                                <th class="text-center pe-3">Remind</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($notSavedMembers)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">
                                <i class="bi bi-check-circle fs-2 d-block mb-2 opacity-25"></i>
                                Every active member has saved this week.
                            </td></tr>
                            <?php else: foreach ($notSavedMembers as $nm):
                                $wa  = waNumber($nm['phone'] ?? '');
                                $msg = "Hi " . $nm['first_name'] . ", this is a friendly reminder from " . APP_NAME .
                                       " to make your savings deposit for this week. Thank you!";
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <a href="<?= APP_URL ?>/index.php?page=member-view&id=<?= $nm['id'] ?>"
                                       class="fw-semibold text-decoration-none small text-dark">
                                        <?= htmlspecialchars($nm['first_name'] . ' ' . $nm['last_name']) ?>
                                    </a>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($nm['account_number'] ?: $nm['member_number']) ?></td>
                                <td class="small"><?= htmlspecialchars($nm['phone'] ?: '—') ?></td>
                                <td class="text-end small">
                                    <?php if ($nm['last_saved_amount'] !== null): ?>
                                        Shs <?= number_format($nm['last_saved_amount'], 0) ?>
                                        <div class="text-muted" style="font-size:.68rem"><?= date('d M Y', strtotime($nm['last_saved_date'])) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">Never saved</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center pe-3">
                                    <?php if ($wa !== ''): ?>
                                    <a href="https://wa.me/<?= $wa ?>?text=<?= urlencode($msg) ?>" target="_blank" rel="noopener"
                                       class="btn btn-sm" style="background:#25D366;color:#fff;border:none;">
                                        <i class="bi bi-whatsapp"></i>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted small">No phone</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal: Loans due this week ───────────────────────────────── -->
<div class="modal fade" id="dueWeekLoansModal" tabindex="-1" aria-labelledby="dueWeekLoansModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="dueWeekLoansModalLabel">
                    <i class="bi bi-calendar-week-fill text-warning me-2"></i>Loans Due This Week
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-3">Name</th>
                                <th>Loan No.</th>
                                <th>Contact</th>
                                <th class="text-end">Outstanding</th>
                                <th class="text-end">Due</th>
                                <th class="text-center pe-3">Remind</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($dueWeekLoansList)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">
                                <i class="bi bi-check-circle fs-2 d-block mb-2 opacity-25"></i>
                                No loans due this week.
                            </td></tr>
                            <?php else: foreach ($dueWeekLoansList as $dl):
                                $wa  = waNumber($dl['phone'] ?? '');
                                $due = (int)$dl['days_remaining'] === 0 ? 'today' : ('in ' . $dl['days_remaining'] . ' day' . ($dl['days_remaining'] == 1 ? '' : 's'));
                                $msg = "Hi " . $dl['first_name'] . ", this is a friendly reminder from " . APP_NAME .
                                       " that your loan repayment of Shs " . number_format($dl['outstanding'], 0) .
                                       " is due on " . date('d M Y', strtotime($dl['due_date'])) . ". Thank you!";
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <a href="<?= APP_URL ?>/index.php?page=loan-view&id=<?= $dl['id'] ?>"
                                       class="fw-semibold text-decoration-none small text-dark">
                                        <?= htmlspecialchars($dl['first_name'] . ' ' . $dl['last_name']) ?>
                                    </a>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($dl['loan_number']) ?></td>
                                <td class="small"><?= htmlspecialchars($dl['phone'] ?: '—') ?></td>
                                <td class="text-end small">Shs <?= number_format($dl['outstanding'], 0) ?></td>
                                <td class="text-end small">
                                    <?= date('d M Y', strtotime($dl['due_date'])) ?>
                                    <div class="text-muted" style="font-size:.68rem">Due <?= $due ?></div>
                                </td>
                                <td class="text-center pe-3">
                                    <?php if ($wa !== ''): ?>
                                    <a href="https://wa.me/<?= $wa ?>?text=<?= urlencode($msg) ?>" target="_blank" rel="noopener"
                                       class="btn btn-sm" style="background:#25D366;color:#fff;border:none;">
                                        <i class="bi bi-whatsapp"></i>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted small">No phone</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal: Overdue loans ─────────────────────────────────────── -->
<div class="modal fade" id="overdueLoansModal" tabindex="-1" aria-labelledby="overdueLoansModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="overdueLoansModalLabel">
                    <i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Overdue Loans
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-3">Name</th>
                                <th>Loan No.</th>
                                <th>Contact</th>
                                <th class="text-end">Outstanding</th>
                                <th class="text-end">Overdue By</th>
                                <th class="text-center pe-3">Remind</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($overdueLoansList)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">
                                <i class="bi bi-check-circle fs-2 d-block mb-2 opacity-25"></i>
                                No overdue loans.
                            </td></tr>
                            <?php else: foreach ($overdueLoansList as $ol):
                                $wa  = waNumber($ol['phone'] ?? '');
                                $msg = "Hi " . $ol['first_name'] . ", this is a friendly reminder from " . APP_NAME .
                                       " that your loan repayment of Shs " . number_format($ol['outstanding'], 0) .
                                       " is now overdue. Please make a repayment at your earliest convenience. Thank you!";
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <a href="<?= APP_URL ?>/index.php?page=loan-view&id=<?= $ol['id'] ?>"
                                       class="fw-semibold text-decoration-none small text-dark">
                                        <?= htmlspecialchars($ol['first_name'] . ' ' . $ol['last_name']) ?>
                                    </a>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($ol['loan_number']) ?></td>
                                <td class="small"><?= htmlspecialchars($ol['phone'] ?: '—') ?></td>
                                <td class="text-end small">Shs <?= number_format($ol['outstanding'], 0) ?></td>
                                <td class="text-end small">
                                    <span class="text-danger fw-semibold"><?= $ol['days_overdue'] ?> day<?= $ol['days_overdue'] == 1 ? '' : 's' ?></span>
                                    <div class="text-muted" style="font-size:.68rem">since <?= date('d M Y', strtotime($ol['due_date'])) ?></div>
                                </td>
                                <td class="text-center pe-3">
                                    <?php if ($wa !== ''): ?>
                                    <a href="https://wa.me/<?= $wa ?>?text=<?= urlencode($msg) ?>" target="_blank" rel="noopener"
                                       class="btn btn-sm" style="background:#25D366;color:#fff;border:none;">
                                        <i class="bi bi-whatsapp"></i>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted small">No phone</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($isTreasurer && $treasurerFinancials): ?>
<!-- ── Financial Overview + Accounting Alerts (Treasurer only) ─── -->
<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-bar-chart-line-fill me-2 text-primary"></i>Financial Overview
                    <?php if ($treasurerFinancials['activeFinancialYear']): ?>
                    <span class="text-muted small fw-normal">— <?= htmlspecialchars($treasurerFinancials['activeFinancialYear']['name']) ?></span>
                    <?php endif; ?>
                </h6>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small fw-semibold text-success"><i class="bi bi-arrow-up-circle-fill me-1"></i>Income</span>
                    <span class="small fw-bold">Shs <?= number_format($treasurerFinancials['totalIncome'], 2) ?></span>
                </div>
                <div class="progress mb-3" style="height:10px;">
                    <?php
                    $incomeExpenseMax = max($treasurerFinancials['totalIncome'], $treasurerFinancials['totalExpense'], 1);
                    $incomePct = min(100, round(($treasurerFinancials['totalIncome'] / $incomeExpenseMax) * 100));
                    ?>
                    <div class="progress-bar bg-success" style="width:<?= $incomePct ?>%"></div>
                </div>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small fw-semibold text-danger"><i class="bi bi-arrow-down-circle-fill me-1"></i>Expenses</span>
                    <span class="small fw-bold">Shs <?= number_format($treasurerFinancials['totalExpense'], 2) ?></span>
                </div>
                <div class="progress mb-3" style="height:10px;">
                    <?php $expensePct = min(100, round(($treasurerFinancials['totalExpense'] / $incomeExpenseMax) * 100)); ?>
                    <div class="progress-bar bg-danger" style="width:<?= $expensePct ?>%"></div>
                </div>
                <hr>
                <div class="d-flex align-items-center justify-content-between">
                    <span class="fw-semibold">Net <?= $treasurerFinancials['netSurplus'] >= 0 ? 'Surplus' : 'Deficit' ?></span>
                    <span class="fw-bold <?= $treasurerFinancials['netSurplus'] >= 0 ? 'text-success' : 'text-danger' ?>">
                        Shs <?= number_format(abs($treasurerFinancials['netSurplus']), 2) ?>
                    </span>
                </div>
                <div class="text-muted small mt-3">
                    <a href="<?= APP_URL ?>/index.php?page=report-income-statement" class="text-decoration-none">
                        View full Income Statement <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-clipboard-check-fill me-2 text-warning"></i>Accounting Alerts
                </h6>
            </div>
            <div class="card-body">
                <?php if ($treasurerFinancials['currentPeriod']): ?>
                <div class="d-flex align-items-center justify-content-between mb-3 pb-3 border-bottom">
                    <div>
                        <div class="small text-muted">Current Accounting Period</div>
                        <div class="fw-semibold"><?= htmlspecialchars($treasurerFinancials['currentPeriod']['name']) ?></div>
                        <div class="small text-muted">
                            <?= date('d M Y', strtotime($treasurerFinancials['currentPeriod']['start_date'])) ?>
                            – <?= date('d M Y', strtotime($treasurerFinancials['currentPeriod']['end_date'])) ?>
                        </div>
                    </div>
                    <span class="badge bg-success-subtle text-success">Open</span>
                </div>
                <?php else: ?>
                <div class="alert alert-warning py-2 px-3 mb-3 small">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>No accounting period is currently open for today's date.
                </div>
                <?php endif; ?>

                <?php $awaitingCount = count($treasurerFinancials['vouchersAwaitingPosting']); ?>
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="fw-semibold">Vouchers Awaiting Posting</div>
                        <div class="small text-muted">Approved by Chairman — ready for you to post</div>
                    </div>
                    <span class="badge <?= $awaitingCount > 0 ? 'bg-warning-subtle text-warning' : 'bg-secondary-subtle text-secondary' ?> rounded-pill fs-6 px-3">
                        <?= $awaitingCount ?>
                    </span>
                </div>
                <?php if ($awaitingCount > 0): ?>
                <div class="mt-2">
                    <a href="<?= APP_URL ?>/index.php?page=internal-vouchers&status=approved" class="btn btn-sm btn-outline-warning">
                        Review &amp; Post <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── ROW 2: Savings Trend + Top Performers ──────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-bar-chart-fill me-2 text-success"></i>Deposit Analytics
                </h6>
                <label class="visually-hidden" for="depositAnalyticsPeriod">Deposit Analytics period</label>
                <select id="depositAnalyticsPeriod" class="form-select form-select-sm" style="width:auto;" aria-label="Deposit Analytics period">
                    <option value="3">3 Months To Date</option>
                    <option value="6" selected>6 Months To Date</option>
                    <option value="12">12 Months To Date</option>
                </select>
            </div>
            <div class="card-body position-relative">
                <div id="depositAnalyticsLoading" class="text-center text-muted small py-5 d-none">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    Loading…
                </div>
                <div id="depositAnalyticsEmpty" class="text-center text-muted small py-5 d-none">
                    <i class="bi bi-bar-chart fs-2 d-block mb-2 opacity-25" aria-hidden="true"></i>
                    No deposit activity for this period.
                </div>
                <div id="depositAnalyticsError" class="text-center text-danger small py-5 d-none">
                    <i class="bi bi-exclamation-triangle fs-2 d-block mb-2 opacity-50" aria-hidden="true"></i>
                    Unable to load deposit analytics.
                </div>
                <!-- Explicit height below 576px only: renderChart() sets
                     maintainAspectRatio:false there (an aspect-ratio-based
                     height doesn't leave enough room for the legend+ticks
                     once axis titles are hidden), and Chart.js has nothing
                     else to size the canvas against without a real
                     container height -- omitting this collapsed the whole
                     chart to a sliver on mobile. Left unset at >=576px so
                     desktop's existing maintainAspectRatio:true sizing
                     (unaffected by this bug) is not constrained by a
                     height that was never tuned for it. -->
                <style>
                    @media (max-width: 575.98px) {
                        #depositAnalyticsCanvasWrap { height: 260px; }
                    }
                </style>
                <div id="depositAnalyticsCanvasWrap" style="max-width:100%;overflow-x:hidden;">
                    <canvas id="savingsMonthlyChart" height="90" role="img" aria-label="Deposit Analytics chart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-trophy-fill me-2 text-warning"></i>Top Performers
                </h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-3">Category</th>
                                <th>Member</th>
                                <th>Member No.</th>
                                <th class="text-end pe-3">Amount (Shs)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="ps-3"><i class="bi bi-piggy-bank-fill me-2 text-success"></i>Top Saver</td>
                                <?php if ($topSaver): ?>
                                <td class="fw-semibold">
                                    <a href="<?= APP_URL ?>/index.php?page=member-view&id=<?= $topSaver['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($topSaver['first_name'] . ' ' . $topSaver['last_name']) ?>
                                    </a>
                                </td>
                                <td class="text-muted small"><?= htmlspecialchars($topSaver['member_number']) ?></td>
                                <td class="text-end pe-3 fw-bold text-success"><?= number_format($topSaver['balance'], 2) ?></td>
                                <?php else: ?>
                                <td colspan="3" class="text-muted small">No savings data yet.</td>
                                <?php endif; ?>
                            </tr>
                            <tr>
                                <td class="ps-3"><i class="bi bi-bank2 me-2 text-warning"></i>Top Borrower</td>
                                <?php if ($topBorrower): ?>
                                <td class="fw-semibold">
                                    <a href="<?= APP_URL ?>/index.php?page=member-view&id=<?= $topBorrower['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($topBorrower['first_name'] . ' ' . $topBorrower['last_name']) ?>
                                    </a>
                                </td>
                                <td class="text-muted small"><?= htmlspecialchars($topBorrower['member_number']) ?></td>
                                <td class="text-end pe-3 fw-bold text-warning"><?= number_format($topBorrower['total_borrowed'], 2) ?></td>
                                <?php else: ?>
                                <td colspan="3" class="text-muted small">No loan data yet.</td>
                                <?php endif; ?>
                            </tr>
                            <tr>
                                <td class="ps-3"><i class="bi bi-pie-chart-fill me-2 text-info"></i>Top Share Holder</td>
                                <?php if ($topShareholder): ?>
                                <td class="fw-semibold">
                                    <a href="<?= APP_URL ?>/index.php?page=member-view&id=<?= $topShareholder['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($topShareholder['first_name'] . ' ' . $topShareholder['last_name']) ?>
                                    </a>
                                </td>
                                <td class="text-muted small"><?= htmlspecialchars($topShareholder['member_number']) ?></td>
                                <td class="text-end pe-3 fw-bold text-info"><?= number_format($topShareholder['total_retained'], 2) ?></td>
                                <?php else: ?>
                                <td colspan="3" class="text-muted small">No shares data yet.</td>
                                <?php endif; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── ROW 3: Recent Savings + Recent Loans ──────────────────── -->
<div class="row g-4 mb-4">

    <!-- Recent Savings -->
    <div class="<?= $canSeeLoanWidgets ? 'col-xl-6' : 'col-xl-12' ?>">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-piggy-bank me-2 text-success"></i>Recent Savings
                </h6>
                <a href="<?= APP_URL ?>/index.php?page=savings"
                   class="btn btn-sm btn-outline-success">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-3">Receipt</th>
                                <th>Member</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end pe-3 d-none d-lg-table-cell">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentTransactions)): ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted">
                                No savings yet.
                                <a href="<?= APP_URL ?>/index.php?page=savings-add">Record first deposit.</a>
                            </td></tr>
                            <?php else: foreach($recentTransactions as $t): ?>
                            <tr>
                                <td class="ps-3">
                                    <a href="<?= APP_URL ?>/index.php?page=savings-view&id=<?= $t['id'] ?>"
                                       class="fw-semibold text-success text-decoration-none small">
                                        <?= htmlspecialchars($t['receipt_number']) ?>
                                    </a>
                                </td>
                                <td class="small fw-semibold">
                                    <?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?>
                                </td>
                                <td class="text-end fw-bold text-success small">
                                    Shs <?= number_format($t['amount'],2) ?>
                                </td>
                                <td class="text-end pe-3 text-muted small d-none d-lg-table-cell">
                                    <?= date('d M Y', strtotime($t['transaction_date'])) ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canSeeLoanWidgets): ?>
    <!-- Recent Loans -->
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-bank2 me-2 text-warning"></i>Recent Loans
                </h6>
                <a href="<?= APP_URL ?>/index.php?page=loans"
                   class="btn btn-sm btn-outline-warning">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-3">Loan No.</th>
                                <th>Member</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center pe-3">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentLoans)): ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted">
                                No loans yet.
                                <?php if (Session::hasRole(['admin', 'loans_officer'])): ?>
                                <a href="<?= APP_URL ?>/index.php?page=loan-add">Record first loan.</a>
                                <?php endif; ?>
                            </td></tr>
                            <?php else: foreach($recentLoans as $l): ?>
                            <tr>
                                <td class="ps-3">
                                    <a href="<?= APP_URL ?>/index.php?page=loan-view&id=<?= $l['id'] ?>"
                                       class="fw-semibold text-decoration-none small"
                                       style="color:var(--brand-navy)">
                                        <?= htmlspecialchars($l['loan_number']) ?>
                                    </a>
                                </td>
                                <td class="small fw-semibold">
                                    <?= htmlspecialchars($l['first_name'].' '.$l['last_name']) ?>
                                </td>
                                <td class="text-end small">Shs <?= number_format($l['loan_amount'],2) ?></td>
                                <td class="text-center pe-3">
                                    <span class="badge rounded-pill px-2
                                        <?= match($l['status']){
                                            'active'    => 'bg-success-subtle text-success',
                                            'overdue'   => 'bg-danger-subtle text-danger',
                                            default     => 'bg-primary-subtle text-primary'
                                        } ?>">
                                        <?= ucfirst($l['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.row savings+loans -->

<!-- ── ROW 4: Recent Repayments ─────────────────────────────── -->
<?php if ($canSeeLoanWidgets): ?>
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-arrow-down-circle-fill me-2"
                       style="color:var(--brand-orange)"></i>Recent Repayments
                </h6>
                <div class="d-flex gap-2 align-items-center">
                    <span class="text-muted small d-none d-sm-inline">
                        Today: <strong class="text-success">Shs <?= number_format($todayRepayments,2) ?></strong>
                    </span>
                    <a href="<?= APP_URL ?>/index.php?page=repayments"
                       class="btn btn-sm"
                       style="border:1px solid var(--brand-orange);color:var(--slate)">
                        View All
                    </a>
                    <a href="<?= APP_URL ?>/index.php?page=repayment-add"
                       class="btn btn-sm text-white"
                       style="background:var(--brand-orange)">
                        + Record
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-3">Receipt</th>
                                <th>Member</th>
                                <th class="d-none d-md-table-cell">Loan No.</th>
                                <th class="text-end">Amount Paid</th>
                                <th class="text-end d-none d-lg-table-cell">Balance After</th>
                                <th class="text-end pe-3 d-none d-lg-table-cell">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentRepayments)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">
                                <i class="bi bi-arrow-down-circle fs-2 d-block mb-2 opacity-25"></i>
                                No repayments yet.
                                <a href="<?= APP_URL ?>/index.php?page=repayment-add">
                                    Record the first payment.
                                </a>
                            </td></tr>
                            <?php else: foreach($recentRepayments as $rp): ?>
                            <tr>
                                <td class="ps-3">
                                    <a href="<?= APP_URL ?>/index.php?page=repayment-view&id=<?= $rp['id'] ?>"
                                       class="fw-semibold text-decoration-none small"
                                       style="color:var(--ink)">
                                        <?= htmlspecialchars($rp['repayment_number']) ?>
                                    </a>
                                </td>
                                <td class="small fw-semibold">
                                    <?= htmlspecialchars($rp['first_name'].' '.$rp['last_name']) ?>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <a href="<?= APP_URL ?>/index.php?page=loan-view&id=<?= $rp['loan_id'] ?>"
                                       class="small text-decoration-none" style="color:var(--brand-navy)">
                                        <?= htmlspecialchars($rp['loan_number']) ?>
                                    </a>
                                </td>
                                <td class="text-end fw-bold text-success small">
                                    Shs <?= number_format($rp['amount_paid'],2) ?>
                                </td>
                                <td class="text-end d-none d-lg-table-cell small
                                    <?= $rp['balance_after']<=0?'text-success':'text-danger' ?>">
                                    Shs <?= number_format($rp['balance_after'],2) ?>
                                </td>
                                <td class="text-end pe-3 text-muted small d-none d-lg-table-cell">
                                    <?= date('d M Y', strtotime($rp['payment_date'])) ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div><!-- /.row repayments -->
<?php endif; ?>

<?php if ($isTreasurer): ?>
<!-- ── Recent Withdrawals (Treasurer only) ─────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-box-arrow-up-right me-2" style="color:var(--brand-orange)"></i>Recent Withdrawals
                </h6>
                <a href="<?= APP_URL ?>/index.php?page=withdrawals" class="btn btn-sm" style="border:1px solid var(--brand-orange);color:var(--slate)">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-3">Reference</th>
                                <th>Member</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end pe-3 d-none d-lg-table-cell">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentWithdrawals)): ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted">No withdrawals yet.</td></tr>
                            <?php else: foreach ($recentWithdrawals as $w): ?>
                            <tr>
                                <td class="ps-3 small fw-semibold"><?= htmlspecialchars($w['withdrawal_number']) ?></td>
                                <td class="small fw-semibold"><?= htmlspecialchars($w['first_name'] . ' ' . $w['last_name']) ?></td>
                                <td class="text-end fw-bold text-danger small">Shs <?= number_format($w['withdrawal_amount'], 2) ?></td>
                                <td class="text-end pe-3 text-muted small d-none d-lg-table-cell"><?= date('d M Y', strtotime($w['withdrawal_date'])) ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── ROW 5: Quick Actions + Recent Members ─────────────────── -->
<div class="row g-3">

    <!-- Quick Actions -->
    <div class="col-xl-3 col-lg-4">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-lightning-charge me-2 text-warning"></i>Quick Actions
                </h6>
            </div>
            <div class="card-body pt-3">
                <!-- 2-per-row on small screens (row-cols-2, mobile-first
                     default); reverts to the original single-column
                     stack from the sm breakpoint up (row-cols-sm-1),
                     where this card is already narrow enough that one
                     button per row still fits and looked fine before. -->
                <div class="row row-cols-2 row-cols-sm-1 g-2">
                    <?php foreach ($quickActions as $qa): ?>
                    <div class="col">
                        <a href="<?= $qa['url'] ?>"
                           class="btn <?= $qa['class'] ?> d-flex align-items-center justify-content-center justify-content-sm-start gap-2 w-100 h-100 text-center text-sm-start">
                            <i class="bi <?= $qa['icon'] ?> fs-5 flex-shrink-0"></i>
                            <span><?= htmlspecialchars($qa['label']) ?></span>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Members -->
    <div class="col-xl-9 col-lg-8">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-person-lines-fill me-2 text-primary"></i>Recent Members
                </h6>
                <a href="<?= APP_URL ?>/index.php?page=members"
                   class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if (empty($recentMembers)): ?>
                    <li class="list-group-item text-center text-muted py-3 small">
                        No members yet.
                    </li>
                    <?php else: foreach($recentMembers as $m): ?>
                    <li class="list-group-item px-3 py-2">
                        <a href="<?= APP_URL ?>/index.php?page=member-view&id=<?= $m['id'] ?>"
                           class="d-flex align-items-center gap-2 text-decoration-none">
                            <div class="member-avatar-sm
                                <?= $m['gender']==='Female'?'bg-pink':'bg-blue' ?> flex-shrink-0">
                                <?= strtoupper(substr($m['first_name'],0,1)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold small text-dark">
                                    <?= htmlspecialchars($m['first_name'].' '.$m['last_name']) ?>
                                </div>
                                <div class="text-muted" style="font-size:.72rem">
                                    <?= htmlspecialchars($m['member_number']) ?>
                                </div>
                            </div>
                            <span class="badge rounded-pill ms-auto"
                                  style="font-size:.65rem;<?= $m['status']==='active'
                                      ?'background:#d1e7dd;color:#0a3622'
                                      :'background:#e2e3e5;color:#495057' ?>">
                                <?= ucfirst($m['status']) ?>
                            </span>
                        </a>
                    </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
        </div>
    </div>

</div><!-- /.row quick actions + members -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const canvas       = document.getElementById('savingsMonthlyChart');
    const canvasWrap    = document.getElementById('depositAnalyticsCanvasWrap');
    const loadingEl     = document.getElementById('depositAnalyticsLoading');
    const emptyEl       = document.getElementById('depositAnalyticsEmpty');
    const errorEl       = document.getElementById('depositAnalyticsError');
    const periodSelect  = document.getElementById('depositAnalyticsPeriod');
    if (!canvas) { return; }

    // Full precision for the tooltip (the actual financial figure);
    // compact for axis ticks only (500K/1.2M/1.24B) so a single very
    // large month (production currently has one, ~1.24 billion Shs)
    // never forces the axis into unreadable, cramped full-digit labels.
    // Presentation-only -- the underlying value passed to Chart.js is
    // never rounded or altered, only its on-axis LABEL is abbreviated.
    const fmtShsFull = v => 'Shs ' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 });
    const fmtShsCompact = v => {
        const n = Number(v);
        const abs = Math.abs(n);
        if (abs >= 1e9) return 'Shs ' + (n / 1e9).toFixed(abs % 1e9 === 0 ? 0 : 1) + 'B';
        if (abs >= 1e6) return 'Shs ' + (n / 1e6).toFixed(abs % 1e6 === 0 ? 0 : 1) + 'M';
        if (abs >= 1e3) return 'Shs ' + (n / 1e3).toFixed(abs % 1e3 === 0 ? 0 : 1) + 'K';
        return 'Shs ' + n.toLocaleString();
    };

    function setState(state) {
        loadingEl.classList.toggle('d-none', state !== 'loading');
        emptyEl.classList.toggle('d-none', state !== 'empty');
        errorEl.classList.toggle('d-none', state !== 'error');
        canvasWrap.classList.toggle('d-none', state !== 'ready');
    }

    let chart = null;
    let currentRows = [];

    // Deposits/withdrawals as bars share one axis (both are real Shs
    // figures for a single month, directly comparable). Growth is a
    // CUMULATIVE running total across the whole selected window and is
    // deliberately plotted on this SAME single axis, matching the
    // simpler reference layout the user explicitly chose over the
    // earlier dual-axis version -- disclosed tradeoff: whichever series
    // has the largest value in the selected window sets the axis scale,
    // so a month with a much larger growth/deposit figure than the rest
    // (production currently has one, ~1.24B Shs) will visually flatten
    // the smaller months. Never more than one Chart instance exists at
    // a time: a period change destroys the previous instance before
    // creating the next, rather than layering a second canvas/chart.
    function renderChart(rows) {
        const isMobile = window.innerWidth < 576;
        const labels      = rows.map(d => d.label);
        const deposits    = rows.map(d => parseFloat(d.deposits));
        const withdrawals = rows.map(d => parseFloat(d.withdrawals));
        const growth      = rows.map(d => parseFloat(d.growth));

        if (chart) { chart.destroy(); }
        chart = new Chart(canvas, {
            data: {
                labels: labels,
                datasets: [
                    {
                        type: 'line',
                        label: 'Growth',
                        data: growth,
                        borderColor: '#198754',
                        backgroundColor: '#198754',
                        borderWidth: 2,
                        tension: 0.4,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#198754',
                        pointBorderWidth: 2,
                        pointRadius: isMobile ? 3 : 4,
                        order: 1
                    },
                    {
                        type: 'bar',
                        label: 'Deposit',
                        data: deposits,
                        backgroundColor: '#3b82f6',
                        borderRadius: 4,
                        order: 2
                    },
                    {
                        type: 'bar',
                        label: 'Withdraw',
                        data: withdrawals,
                        backgroundColor: '#64748b',
                        borderRadius: 4,
                        order: 3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: !isMobile,
                // Keeps the tooltip anchored inside the canvas/card on
                // narrow viewports instead of drifting toward/past the
                // screen edge under the cursor's raw position.
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, pointStyle: 'circle', padding: isMobile ? 12 : 20, boxWidth: 8, font: { size: isMobile ? 10 : 12 } }
                    },
                    tooltip: {
                        backgroundColor: '#334155',
                        titleFont: { weight: 'bold' },
                        bodySpacing: 6,
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: { label: ctx => `${ctx.dataset.label}: ${fmtShsFull(ctx.parsed.y)}` }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        // Kept flat/horizontal at every width -- 6-8
                        // short "MMM YYYY" labels at 9px comfortably fit
                        // a phone-width card without rotating, and
                        // rotation was eating into the limited vertical
                        // space a fixed-height mobile canvas already has.
                        ticks: { maxRotation: 0, minRotation: 0, font: { size: isMobile ? 9 : 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { callback: fmtShsCompact, font: { size: isMobile ? 9 : 11 } },
                        grid: { color: '#f1f5f9' }
                    }
                }
            }
        });
    }

    function loadPeriod(months) {
        setState('loading');
        fetch('<?= APP_URL ?>/index.php?page=dashboard-deposit-analytics&months=' + encodeURIComponent(months), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(r => { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
            .then(json => {
                if (!json || json.success !== true || !Array.isArray(json.rows)) { throw new Error('bad payload'); }
                const rows = json.rows;
                currentRows = rows;
                const hasActivity = rows.some(r => parseFloat(r.deposits) !== 0 || parseFloat(r.withdrawals) !== 0);
                if (!hasActivity) {
                    setState('empty');
                    return;
                }
                renderChart(rows);
                setState('ready');
            })
            .catch(() => { setState('error'); });
    }

    // Initial paint reuses the data the controller already rendered
    // server-side (no redundant fetch on first load); the dropdown
    // triggers depositAnalyticsData() for every subsequent change.
    currentRows = <?= json_encode($monthlySavingsChart) ?>;
    const initialHasActivity = currentRows.some(r => parseFloat(r.deposits) !== 0 || parseFloat(r.withdrawals) !== 0);
    if (currentRows.length > 0 && initialHasActivity) {
        renderChart(currentRows);
        setState('ready');
    } else if (currentRows.length > 0) {
        setState('empty');
    }

    if (periodSelect) {
        periodSelect.addEventListener('change', function () { loadPeriod(this.value); });
    }

    // Re-render from the data already held in memory (never a new
    // network request) so the mobile-vs-desktop tick/legend/point-size
    // choices above stay correct across an orientation change/resize.
    let resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            if (chart && currentRows.length > 0) { renderChart(currentRows); }
        }, 300);
    });
});
</script>
