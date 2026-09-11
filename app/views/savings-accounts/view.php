<?php
/* ─────────────────────────────────────────────────────────────────────
   savings-accounts/view.php
   Works for all account types.  Corporate gets its own layout branch.

   2026-09: converted from a page-local bespoke CSS system (sv-*) to the
   app's shared design system (.card/.card-header/.stat-card/.detail-label
   /.detail-value/.table), matching every other account/record detail
   page (e.g. repayments/view.php, expenses/view.php) -- this page's own
   Account Adjustments section at the bottom already used the shared
   system, so it visibly clashed with everything above it. Presentation
   only: every PHP variable, condition, and computed value below is
   unchanged from before this conversion.
───────────────────────────────────────────────────────────────────── */
$pageTitle = $pageTitle ?? 'Savings Account';
$base      = APP_URL . '/index.php';

$typeLabels = [
    'compulsory'    => 'Compulsory Savings',
    'voluntary'     => 'Voluntary Savings',
    'joint'         => 'Joint Savings',
    'corporate'     => 'Corporate Savings',
    'fixed_deposit' => 'Fixed Deposit',
];
$isCorporate = $account['account_type'] === 'corporate';
$isFixedDeposit = $account['account_type'] === 'fixed_deposit';
$isBfEligible = in_array($account['account_type'], ['compulsory', 'voluntary'], true);

// ── Authoritative totals — passed directly from controller ────────
// These are computed from the full savings table, not from the
// paginated $transactions slice (which is limited to 100 rows).
$totalDeposits    = (float)($totalDeposits    ?? 0);
$totalWithdrawals = (float)($totalWithdrawals ?? 0);

// ── Holder info ───────────────────────────────────────────────────
$primaryHolder = null;
$jointHolders  = [];
foreach ($holders as $h) {
    if ($account['account_type'] === 'joint') {
        $jointHolders[] = $h;
    } elseif (empty($h['organization_id'])) {
        $primaryHolder = $h;
    }
}

// ── Status display ────────────────────────────────────────────────
// Corporate accounts that can't transact are presented as
// "Pending Setup" to the user — not "Active" — avoiding contradiction.
$displayStatus      = $account['status'];
$displayStatusBadge = 'bg-secondary';
if ($isCorporate && !$canTransact) {
    $displayStatus      = 'Pending Setup';
    $displayStatusBadge = 'bg-warning text-dark';
} elseif ($account['status'] === 'active') {
    $displayStatusBadge = 'bg-success';
} elseif ($account['status'] === 'dormant') {
    $displayStatusBadge = 'bg-warning text-dark';
} elseif ($account['status'] === 'closed') {
    $displayStatusBadge = 'bg-secondary';
} elseif ($account['status'] === 'matured') {
    $displayStatusBadge = 'bg-info text-dark';
}

// Stage FD-2: $fdClosureRequest is passed in by the controller (the most
// recent closure request for this account, if any) -- not queried here;
// views don't open their own database connections in this codebase.
$fdClosureRequest = $fdClosureRequest ?? null;
$fdCanRequestClosure = $isFixedDeposit && $account['status'] === 'matured'
    && (!$fdClosureRequest || in_array($fdClosureRequest['status'], ['rejected', 'cancelled'], true))
    && Session::hasRole(['admin', 'office_admin']);

// Stage 11 — the same idea generalized to the other five account types.
// $closureRequest/$closureEligibility are passed in by the controller.
$closureRequest = $closureRequest ?? null;
$closureEligibility = $closureEligibility ?? null;
$universalClosureTypes = ['compulsory', 'voluntary', 'joint', 'corporate'];
$showsUniversalClosure = in_array($account['account_type'], $universalClosureTypes, true);
$canRequestClosure = $showsUniversalClosure
    && $closureEligibility && $closureEligibility['eligible']
    && Session::hasRole(['admin', 'office_admin']);

ob_start(); ?>
<a href="<?= $base ?>?page=savings-account-statement&id=<?= $account['id'] ?>"
   class="btn btn-sm btn-outline-secondary" target="_blank">
    <i class="bi bi-file-earmark-text me-1"></i>Statement
</a>
<?php if ($canDeposit && $canTransact): ?>
<a href="<?= $base ?>?page=savings-account-deposit&id=<?= $account['id'] ?>" class="btn btn-sm btn-primary">
    <i class="bi bi-plus me-1"></i>Record Deposit
</a>
<?php endif; ?>
<?php
$cta = ob_get_clean();

$subtitleParts = [htmlspecialchars($typeLabels[$account['account_type']] ?? $account['account_type'])];
$subtitleParts[] = '<span class="badge ' . $displayStatusBadge . '">' . htmlspecialchars(ucfirst($displayStatus)) . '</span>';
if ($isCorporate && $organization) {
    $subtitleParts[] = htmlspecialchars($organization['name']) . ' &middot; Corporate account';
} elseif ($primaryHolder) {
    $subtitleParts[] = htmlspecialchars(trim($primaryHolder['first_name'] . ' ' . $primaryHolder['last_name'])) . ' &middot; ' . htmlspecialchars($primaryHolder['member_number']);
} elseif ($account['account_type'] === 'joint' && !empty($jointHolders)) {
    $extra = count($jointHolders) - 1;
    $subtitleParts[] = htmlspecialchars(trim($jointHolders[0]['first_name'] . ' ' . $jointHolders[0]['last_name'])) . ($extra > 0 ? ' &amp; ' . $extra . ' other' . ($extra > 1 ? 's' : '') : '') . ' &middot; Joint account';
}
$subtitle  = implode(' &nbsp;&middot;&nbsp; ', $subtitleParts);
$icon      = 'bi-bank';
$title     = htmlspecialchars($account['account_number']);
?>

<a href="<?= $base ?>?page=savings-accounts" class="text-decoration-none text-muted small d-inline-flex align-items-center gap-1 mb-2">
    <i class="bi bi-arrow-left"></i>Savings Accounts
</a>

<?php require VIEW_PATH . '/layouts/page-title.php'; ?>

<?php if ($msg = Session::flash('success')): ?>
    <div class="alert alert-success alert-dismissible fade show mb-3">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($msg = Session::flash('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-3">
        <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- ── BALANCE METRICS ─────────────────────────────────────────────── -->
<div class="row g-2 mb-4">
    <div class="col-6 col-md-4">
        <div class="stat-card stat-card-primary h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-wallet2"></i></div>
                <div>
                    <div class="stat-label">Current Balance</div>
                    <div class="stat-value" style="font-size:1.1rem"><?= $balance == 0 ? '<span class="text-muted">Shs 0</span>' : 'Shs ' . number_format($balance, 0) ?></div>
                    <div class="stat-sub">Opened <?= date('d M Y', strtotime($account['opened_date'])) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card stat-card-success h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-graph-up-arrow"></i></div>
                <div>
                    <div class="stat-label">Total Deposits</div>
                    <div class="stat-value" style="font-size:1.1rem"><?= $totalDeposits == 0 ? '<span class="text-muted">Shs 0</span>' : 'Shs ' . number_format($totalDeposits, 0) ?></div>
                    <div class="stat-sub"><?= count(array_filter($transactions, fn($t) => (float)($t['credit'] ?? 0) > 0)) ?> transaction<?= count($transactions) !== 1 ? 's' : '' ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card stat-card-danger h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-danger"><i class="bi bi-graph-down-arrow"></i></div>
                <div>
                    <div class="stat-label">Total Withdrawals</div>
                    <div class="stat-value" style="font-size:1.1rem"><?= $totalWithdrawals == 0 ? '<span class="text-muted">Shs 0</span>' : 'Shs ' . number_format($totalWithdrawals, 0) ?></div>
                    <div class="stat-sub"><?= $lastActivity ? 'Last activity ' . date('d M Y', strtotime($lastActivity)) : 'No activity yet' ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── FIXED DEPOSIT PANEL ───────────────────────────────────────── -->
<?php if ($isFixedDeposit): ?>
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-safe me-2"></i>Fixed Deposit Details</h6></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-sm-6 col-md-4">
                <div class="detail-label">Principal</div>
                <div class="detail-value">Shs <?= number_format((float)($account['principal_amount'] ?? 0), 2) ?></div>
            </div>
            <div class="col-sm-6 col-md-4">
                <div class="detail-label">Deposit Date</div>
                <div class="detail-value"><?= $account['deposit_date'] ? date('d M Y', strtotime($account['deposit_date'])) : '—' ?></div>
            </div>
            <div class="col-sm-6 col-md-4">
                <div class="detail-label">Term</div>
                <div class="detail-value"><?= (int)($account['term_months'] ?? 0) ?> Months</div>
            </div>
            <div class="col-sm-6 col-md-4">
                <div class="detail-label">Interest Rate</div>
                <div class="detail-value"><?= number_format((float)($account['interest_rate'] ?? 0), 3) ?>% p.a. (simple)</div>
            </div>
            <div class="col-sm-6 col-md-4">
                <div class="detail-label">Maturity Date</div>
                <div class="detail-value fw-semibold text-danger"><?= $account['maturity_date'] ? date('d M Y', strtotime($account['maturity_date'])) : '—' ?></div>
            </div>
            <div class="col-sm-6 col-md-4">
                <div class="detail-label">Expected Interest</div>
                <div class="detail-value text-success">Shs <?= number_format((float)($account['expected_interest'] ?? 0), 2) ?></div>
            </div>
            <div class="col-sm-6 col-md-4">
                <div class="detail-label">Expected Maturity Amount</div>
                <div class="detail-value fw-bold">Shs <?= number_format((float)($account['expected_maturity_amount'] ?? 0), 2) ?></div>
            </div>
            <div class="col-sm-6 col-md-4">
                <div class="detail-label">Status</div>
                <div class="detail-value"><?= ucfirst($account['status']) ?></div>
            </div>
            <div class="col-sm-6 col-md-4">
                <div class="detail-label">Top-Ups</div>
                <div class="detail-value"><i class="bi bi-lock-fill me-1"></i>Not permitted</div>
            </div>
            <div class="col-sm-6 col-md-4">
                <div class="detail-label">Early Withdrawal</div>
                <div class="detail-value"><i class="bi bi-lock-fill me-1"></i>Not permitted before maturity</div>
            </div>
            <?php if ($fdClosureRequest): ?>
            <div class="col-sm-6 col-md-4">
                <div class="detail-label">Closure Status</div>
                <div class="detail-value">
                    <?php
                    $fdStatusMap = [
                        'pending'   => ['Pending Approval', 'text-warning'],
                        'approved'  => ['Approved — Awaiting Payment', 'text-primary'],
                        'paid'      => ['Paid', 'text-success'],
                        'rejected'  => ['Rejected', 'text-danger'],
                        'cancelled' => ['Cancelled', 'text-muted'],
                    ];
                    [$fdStatusLabel, $fdStatusClass] = $fdStatusMap[$fdClosureRequest['status']] ?? [ucfirst($fdClosureRequest['status']), ''];
                    ?>
                    <span class="fw-semibold <?= $fdStatusClass ?>"><?= htmlspecialchars($fdStatusLabel) ?></span>
                </div>
            </div>
            <?php if ($fdClosureRequest['status'] === 'pending' && (int)$fdClosureRequest['requested_by'] === (int)Session::get('user_id')): ?>
            <div class="col-sm-6 col-md-4 d-flex align-items-end">
                <form method="POST" action="<?= $base ?>?page=savings-account-fd-closure-cancel" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="request_id" value="<?= (int)$fdClosureRequest['id'] ?>">
                    <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Cancel My Request</button>
                </form>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($fdCanRequestClosure): ?>
            <div class="col-sm-6 col-md-4 d-flex align-items-end">
                <a href="<?= $base ?>?page=savings-account-fd-closure-request&id=<?= (int)$account['id'] ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-send me-1"></i>Request Closure
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── STAGE 11: UNIVERSAL CLOSURE (compulsory/voluntary/joint/corporate) ── -->
<?php if ($showsUniversalClosure): ?>
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-door-closed me-2"></i>Account Closure</h6></div>
    <div class="card-body">
        <div class="row g-3">
        <?php if ($closureRequest): ?>
        <div class="col-sm-6 col-md-4">
            <div class="detail-label">Closure Status</div>
            <div class="detail-value">
                <?php
                $statusMap = [
                    'pending'   => ['Pending Approval', 'text-warning'],
                    'approved'  => ['Approved — Awaiting Settlement', 'text-primary'],
                    'paid'      => ['Closed', 'text-success'],
                    'rejected'  => ['Rejected', 'text-danger'],
                    'cancelled' => ['Cancelled', 'text-muted'],
                ];
                [$statusLabel, $statusClass] = $statusMap[$closureRequest['status']] ?? [ucfirst($closureRequest['status']), ''];
                ?>
                <span class="fw-semibold <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
            </div>
        </div>
        <?php if ($closureRequest['status'] === 'rejected' && !empty($closureRequest['rejection_reason'])): ?>
        <div class="col-sm-6 col-md-4">
            <div class="detail-label">Rejection Reason</div>
            <div class="detail-value"><?= htmlspecialchars($closureRequest['rejection_reason']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($closureRequest['status'] === 'pending' && (int)$closureRequest['requested_by'] === (int)Session::get('user_id')): ?>
        <div class="col-sm-6 col-md-4 d-flex align-items-end">
            <form method="POST" action="<?= $base ?>?page=savings-account-closure-cancel" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="request_id" value="<?= (int)$closureRequest['id'] ?>">
                <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Cancel My Request</button>
            </form>
        </div>
        <?php endif; ?>
        <?php elseif ($closureEligibility && !$closureEligibility['eligible'] && $account['status'] === 'active'): ?>
        <div class="col-sm-6 col-md-4">
            <div class="detail-label">Closure Eligibility</div>
            <div class="detail-value text-muted small"><?= htmlspecialchars($closureEligibility['reason']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($canRequestClosure): ?>
        <div class="col-sm-6 col-md-4 d-flex align-items-end">
            <a href="<?= $base ?>?page=savings-account-closure-request&id=<?= (int)$account['id'] ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-send me-1"></i>Request Closure
            </a>
        </div>
        <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── INFO CARDS ROW ───────────────────────────────────────────── -->
<?php if ($isCorporate): ?>
<div class="row g-3 mb-4">

    <!-- Account Holder -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-building me-2"></i>Account Holder</h6></div>
            <div class="card-body">
                <?php if ($organization): ?>
                    <div class="fw-semibold mb-3"><?= htmlspecialchars($organization['name']) ?></div>
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="detail-label">Registration No.</div>
                            <div class="detail-value"><?= htmlspecialchars($organization['registration_number'] ?? '—') ?></div>
                        </div>
                        <div class="col-12">
                            <div class="detail-label">Phone</div>
                            <div class="detail-value"><?= htmlspecialchars($organization['contact_phone'] ?? '—') ?></div>
                        </div>
                        <div class="col-12">
                            <div class="detail-label">Email</div>
                            <div class="detail-value" style="word-break:break-all;"><?= htmlspecialchars($organization['contact_email'] ?? '—') ?></div>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0">Organization details are not available.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Authorized Representatives -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-people me-2"></i>Authorized Representatives</h6></div>
            <div class="card-body">
                <?php if (!empty($representatives)): ?>
                    <?php foreach ($representatives as $r): ?>
                        <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                            <div>
                                <div class="fw-semibold small"><?= htmlspecialchars($r['full_name']) ?></div>
                                <?php if (!empty($r['role'])): ?>
                                    <div class="text-muted" style="font-size:.75rem;"><?= htmlspecialchars($r['role']) ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if ($r['is_authorized_signatory']): ?>
                                <span class="badge bg-secondary-subtle text-secondary">Signatory</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted small mb-0">No representatives on file.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- ── PENDING SETUP ALERT ───────────────────────────────────────── -->
<?php if (($canWrite || $canDeposit) && !$canTransact): ?>
<div class="alert alert-warning d-flex gap-2 mb-4">
    <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
    <div>
        <div class="fw-semibold mb-1">Account setup incomplete</div>
        <div class="small">
            An authorized representative must be confirmed before deposits and withdrawals can be recorded on this account.
            Contact an administrator to complete the setup.
        </div>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ── NON-CORPORATE INFO ROW ─────────────────────────────────── -->
<div class="row g-3 mb-4">

    <!-- Holder / Members -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold">
                    <?= $account['account_type'] === 'joint' ? '<i class="bi bi-people me-2"></i>Account Holders' : '<i class="bi bi-person me-2"></i>Account Holder' ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if ($account['account_type'] === 'joint'): ?>
                    <?php foreach ($jointHolders as $h): ?>
                        <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                            <div class="d-flex align-items-center gap-2">
                                <span class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold" style="width:36px;height:36px;background:rgba(27,43,107,.08);color:var(--brand-navy);font-size:.78rem;flex-shrink:0;"><?= mb_strtoupper(mb_substr($h['first_name'],0,1).mb_substr($h['last_name'],0,1)) ?></span>
                                <div>
                                    <div class="fw-semibold small"><?= htmlspecialchars(trim($h['first_name'].' '.$h['last_name'])) ?></div>
                                    <div class="text-muted" style="font-size:.75rem;"><?= htmlspecialchars($h['member_number']) ?></div>
                                </div>
                            </div>
                            <?php if ($h['role'] === 'primary'): ?>
                                <span class="badge bg-secondary-subtle text-secondary">Primary</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ($primaryHolder): ?>
                    <div class="d-flex align-items-center gap-3">
                        <span class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold" style="width:36px;height:36px;background:rgba(27,43,107,.08);color:var(--brand-navy);font-size:.78rem;flex-shrink:0;"><?= mb_strtoupper(mb_substr($primaryHolder['first_name'],0,1).mb_substr($primaryHolder['last_name'],0,1)) ?></span>
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars(trim($primaryHolder['first_name'].' '.$primaryHolder['last_name'])) ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($primaryHolder['member_number']) ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="col-md-6">
        <div class="card h-100">
            <?php if ($canWrite || $canDeposit): ?>
                <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-lightning me-2"></i>Quick Actions</h6></div>
                <div class="card-body d-flex flex-column gap-2">
                    <?php if ($canTransact): ?>
                        <?php if ($canDeposit && !$isFixedDeposit): ?>
                        <a href="<?= $base ?>?page=savings-account-deposit&id=<?= $account['id'] ?>" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Record Deposit
                        </a>
                        <?php elseif ($isFixedDeposit): ?>
                        <div class="text-muted small"><i class="bi bi-lock-fill me-1"></i>Top-ups not permitted for Fixed Deposit — one lump-sum principal only</div>
                        <?php endif; ?>
                        <?php if ($canWrite && !$isFixedDeposit): ?>
                        <a href="<?= $base ?>?page=savings-account-withdrawal&id=<?= $account['id'] ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-dash-circle me-1"></i>Record Withdrawal
                        </a>
                        <?php elseif ($isFixedDeposit): ?>
                        <div class="text-muted small"><i class="bi bi-lock-fill me-1"></i>Withdrawal not permitted before maturity (<?= $account['maturity_date'] ? date('d M Y', strtotime($account['maturity_date'])) : '—' ?>)</div>
                        <?php endif; ?>
                        <?php if ($canBroughtForward && $isBfEligible): ?>
                            <?php if ($hasBroughtForward): ?>
                            <a href="<?= $base ?>?page=savings-account-bf-reverse&id=<?= $account['id'] ?>" class="btn btn-outline-danger btn-sm mt-1">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Reverse Balance Brought Forward
                            </a>
                            <?php else: ?>
                            <a href="<?= $base ?>?page=savings-account-bf&id=<?= $account['id'] ?>" class="btn btn-outline-primary btn-sm mt-1">
                                <i class="bi bi-clock-history me-1"></i>Record Balance Brought Forward
                            </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-muted small py-1">Transactions are unavailable while this account is <?= htmlspecialchars($account['status']) ?>.</div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-info-circle me-2"></i>Account Info</h6></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="detail-label">Opened</div>
                            <div class="detail-value"><?= date('d M Y', strtotime($account['opened_date'])) ?></div>
                        </div>
                        <div class="col-6">
                            <div class="detail-label">Last Activity</div>
                            <div class="detail-value"><?= $lastActivity ? date('d M Y', strtotime($lastActivity)) : '—' ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── TRANSACTION HISTORY ────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-clock-history me-2"></i>Transaction History</h6>
        <?php if (!empty($transactions)): ?>
            <span class="text-muted small"><?= count($transactions) ?> transaction<?= count($transactions) !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
    </div>
    <?php if (empty($transactions)): ?>
        <div class="text-center py-5">
            <i class="bi bi-receipt text-muted" style="font-size:2rem;opacity:.25;display:block;margin-bottom:.75rem;"></i>
            <div class="fw-semibold text-muted mb-1">No transactions yet</div>
            <div class="text-muted small">
                <?php if ($isCorporate && !$canTransact): ?>
                    Deposits and withdrawals will appear here once the account is fully set up.
                <?php elseif ($account['status'] !== 'active'): ?>
                    This account is <?= htmlspecialchars($account['status']) ?> and has no recorded transactions.
                <?php else: ?>
                    Deposits and withdrawals will appear here once recorded.
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Receipt</th>
                    <th>Cash Ref.</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th class="text-end">Debit</th>
                    <th class="text-end">Credit</th>
                    <th class="text-end">Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $t): ?>
                    <?php
                    $isCr  = (float)($t['credit'] ?? 0) > 0;
                    $isDr  = (float)($t['debit']  ?? 0) > 0;
                    $runBal = (float)($t['running_balance'] ?? 0);
                    ?>
                    <tr>
                        <td class="small text-nowrap"><?= date('d M Y', strtotime($t['transaction_date'])) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($t['receipt_number'] ?? '—') ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($t['cash_reference_number'] ?? '—') ?></td>
                        <td><span class="badge <?= $isCr ? 'bg-success' : 'bg-danger' ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $t['transaction_type'] ?? ''))) ?></span></td>
                        <td class="small text-muted"><?= htmlspecialchars($t['description'] ?? '') ?></td>
                        <td class="text-end small <?= $isDr ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= $isDr ? number_format((float)$t['debit'], 0) : '—' ?></td>
                        <td class="text-end small <?= $isCr ? 'text-success fw-semibold' : 'text-muted' ?>"><?= $isCr ? number_format((float)$t['credit'], 0) : '—' ?></td>
                        <td class="text-end small fw-semibold"><?= number_format($runBal, 0) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <tr>
                    <td colspan="5" class="fw-semibold">Closing Balance</td>
                    <td colspan="3" class="text-end fw-bold"><?= number_format($balance, 0) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($adjustments)): ?>
<div class="card mt-3">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-sliders me-2"></i>Account Adjustments</span>
        <a href="<?= APP_URL ?>/index.php?page=member-adjustments" class="small">View all &rsaquo;</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Date</th><th>Number</th><th>Type</th><th class="text-end">Amount</th><th>Reason</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($adjustments as $adj): ?>
                <tr>
                    <td class="small"><?= date('d M Y', strtotime($adj['created_at'])) ?></td>
                    <td><a href="<?= APP_URL ?>/index.php?page=member-adjustment-view&id=<?= (int)$adj['id'] ?>" class="small"><?= htmlspecialchars($adj['adjustment_number']) ?></a></td>
                    <td><span class="badge <?= $adj['adjustment_type']==='credit'?'bg-success':'bg-danger' ?>"><?= ucfirst($adj['adjustment_type']) ?></span></td>
                    <td class="text-end small">Shs <?= number_format($adj['amount'], 2) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars(mb_strimwidth($adj['reason'], 0, 60, '…')) ?></td>
                    <td><span class="badge bg-secondary-subtle text-secondary small"><?= ucwords(str_replace('_',' ',$adj['status'])) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
