<?php $base = APP_URL . '/index.php'; ?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;"><?= htmlspecialchars($bonus['reference_number']) ?></h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">
            <span class="badge rounded-pill <?= $bonus['bonus_type'] === 'referral' ? 'bg-info-subtle text-info' : 'bg-warning-subtle text-warning' ?>">
                <?= $bonus['bonus_type'] === 'referral' ? 'Referral Bonus' : 'Staff Target Bonus' ?>
            </span>
        </p>
    </div>
    <a href="<?= $base ?>?page=referral-bonuses" class="btn btn-outline-secondary btn-sm">Back to Register</a>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-sm">
            <tr><th style="width:220px;">Beneficiary</th><td><?= htmlspecialchars($bonus['beneficiary_first_name'] . ' ' . $bonus['beneficiary_last_name']) ?> (<?= htmlspecialchars($bonus['beneficiary_member_number']) ?>)</td></tr>
            <?php if ($bonus['bonus_type'] === 'referral'): ?>
            <tr><th>Referred Member</th><td><?= htmlspecialchars(($bonus['referred_first_name'] ?? '') . ' ' . ($bonus['referred_last_name'] ?? '')) ?> (<?= htmlspecialchars($bonus['referred_member_number'] ?? '—') ?>)</td></tr>
            <?php else: ?>
            <tr><th>Target</th><td><?= htmlspecialchars($bonus['target_note'] ?? '—') ?></td></tr>
            <?php endif; ?>
            <tr><th>Amount</th><td class="fw-bold">UGX <?= number_format($bonus['amount'], 2) ?></td></tr>
            <tr><th>Payment Date</th><td><?= date('d M Y', strtotime($bonus['payment_date'])) ?></td></tr>
            <tr><th>Payment Method</th><td><?= htmlspecialchars($bonus['payment_method']) ?></td></tr>
            <tr><th>Notes</th><td><?= htmlspecialchars($bonus['narration'] ?? '—') ?></td></tr>
            <tr><th>Recorded By</th><td><?= htmlspecialchars($bonus['recorded_by_name'] ?? '—') ?></td></tr>
            <tr><th>Recorded At</th><td><?= date('d M Y H:i', strtotime($bonus['created_at'])) ?></td></tr>
            <tr><th>Journal Entry</th><td>
                <?php if ($bonus['journal_entry_id']): ?>
                    <i class="bi bi-journal-check me-1 text-success"></i><?= htmlspecialchars($bonus['journal_entry_number'] ?? ('#' . $bonus['journal_entry_id'])) ?>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td></tr>
        </table>
        </div>
    </div>
</div>
