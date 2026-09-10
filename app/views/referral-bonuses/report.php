<?php $base = APP_URL . '/index.php'; ?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Referral Bonus Report</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">Total paid per beneficiary, all time</p>
    </div>
    <a href="<?= $base ?>?page=referral-bonuses" class="btn btn-outline-secondary btn-sm">Back to Register</a>
</div>

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
        <div class="small text-muted">Beneficiaries</div><div class="fs-6 fw-bold"><?= count($summary) ?></div>
    </div></div></div>
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0 fw-semibold" style="font-size:.82rem;">By Beneficiary</h6></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Member</th>
                    <th class="text-center">Payouts</th>
                    <th class="text-end">Total Received</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($summary)): ?>
                <tr><td colspan="3" class="text-center py-4 text-muted">No bonus payouts recorded yet.</td></tr>
                <?php else: foreach ($summary as $s): ?>
                <tr>
                    <td class="ps-3 fw-semibold" style="font-size:.78rem;"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?>
                        <div class="text-muted" style="font-size:.68rem;"><?= htmlspecialchars($s['member_number']) ?></div>
                    </td>
                    <td class="text-center"><?= (int)$s['bonus_count'] ?></td>
                    <td class="text-end fw-bold" style="font-variant-numeric:tabular-nums;">UGX <?= number_format($s['total_amount'], 0) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
