<?php $base = APP_URL . '/index.php'; ?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Referral Commissions & Bonuses</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">Payouts to members for referrals and staff-target bonuses</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $base ?>?page=referral-bonus-report" class="btn btn-outline-primary btn-sm"><i class="bi bi-bar-chart me-1"></i>Report</a>
        <?php if ($canWrite): ?>
        <a href="<?= $base ?>?page=referral-bonus-create" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Record Payout</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Totals -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="card text-center"><div class="card-body py-3">
        <div class="small text-muted">Total Paid</div><div class="fs-6 fw-bold">UGX <?= number_format($totals['total_amount'], 0) ?></div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center"><div class="card-body py-3">
        <div class="small text-muted">Referral Bonuses</div><div class="fs-6 fw-bold">UGX <?= number_format($totals['referral_amount'], 0) ?></div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center"><div class="card-body py-3">
        <div class="small text-muted">Staff Target Bonuses</div><div class="fs-6 fw-bold">UGX <?= number_format($totals['staff_target_amount'], 0) ?></div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center"><div class="card-body py-3">
        <div class="small text-muted">Payouts Recorded</div><div class="fs-6 fw-bold"><?= number_format($totals['bonus_count']) ?></div>
    </div></div></div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" action="<?= $base ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="referral-bonuses">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search reference or member..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="referral" <?= $type === 'referral' ? 'selected' : '' ?>>Referral</option>
                    <option value="staff_target" <?= $type === 'staff_target' ? 'selected' : '' ?>>Staff Target</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
            </div>
            <div class="col-auto">
                <a href="<?= $base ?>?page=referral-bonuses" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Register -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold" style="font-size:.82rem;">Bonus Register</h6>
        <span class="badge bg-primary-subtle text-primary"><?= number_format($total) ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Reference</th>
                    <th>Type</th>
                    <th>Beneficiary</th>
                    <th>Details</th>
                    <th class="text-end">Amount</th>
                    <th>Date</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bonuses)): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">No bonus payouts recorded yet.</td></tr>
                <?php else: foreach ($bonuses as $b): ?>
                <tr>
                    <td class="ps-3 fw-semibold" style="font-size:.72rem;"><?= htmlspecialchars($b['reference_number']) ?></td>
                    <td>
                        <span class="badge rounded-pill <?= $b['bonus_type'] === 'referral' ? 'bg-info-subtle text-info' : 'bg-warning-subtle text-warning' ?>">
                            <?= $b['bonus_type'] === 'referral' ? 'Referral' : 'Staff Target' ?>
                        </span>
                    </td>
                    <td class="fw-semibold" style="font-size:.78rem;"><?= htmlspecialchars($b['beneficiary_first_name'] . ' ' . $b['beneficiary_last_name']) ?>
                        <div class="text-muted" style="font-size:.68rem;"><?= htmlspecialchars($b['beneficiary_member_number']) ?></div>
                    </td>
                    <td style="font-size:.72rem;">
                        <?php if ($b['bonus_type'] === 'referral'): ?>
                            Referred: <?= htmlspecialchars($b['referred_first_name'] . ' ' . $b['referred_last_name']) ?>
                        <?php else: ?>
                            <?= htmlspecialchars($b['target_note'] ?? '—') ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-bold" style="font-variant-numeric:tabular-nums;">UGX <?= number_format($b['amount'], 0) ?></td>
                    <td class="text-muted" style="font-size:.72rem;"><?= date('d M Y', strtotime($b['payment_date'])) ?></td>
                    <td class="text-center">
                        <a href="<?= $base ?>?page=referral-bonus-view&id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pages > 1): ?>
<nav class="mt-4"><ul class="pagination pagination-sm justify-content-center">
    <?php for ($p = 1; $p <= min($pages, 10); $p++): ?>
    <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
        <a class="page-link" href="<?= $base ?>?page=referral-bonuses&p=<?= $p ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($type) ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>
