<?php $base = APP_URL . '/index.php'; ?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Member Account Adjustments</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">Accounting &rsaquo; Account Adjustments — correcting a specific member's account, with a matching GL entry</p>
    </div>
    <?php if ($canWrite): ?>
    <a href="<?= $base ?>?page=member-adjustment-create" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>New Adjustment</a>
    <?php endif; ?>
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

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold" style="font-size:.82rem;">Adjustment Register</h6>
        <span class="badge bg-primary-subtle text-primary"><?= count($adjustments) ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Number</th>
                    <th>Member</th>
                    <th>Account</th>
                    <th>Type</th>
                    <th class="text-end">Amount</th>
                    <th class="text-center">Status</th>
                    <th>Prepared By</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($adjustments)): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">No account adjustments recorded yet.</td></tr>
                <?php else: foreach ($adjustments as $a): ?>
                <tr>
                    <td class="ps-3 fw-semibold" style="font-size:.72rem;"><?= htmlspecialchars($a['adjustment_number']) ?>
                        <?php if ($a['reversed_at']): ?><span class="badge bg-secondary ms-1">Reversed</span><?php endif; ?>
                    </td>
                    <td style="font-size:.78rem;"><?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name']) ?>
                        <div class="text-muted" style="font-size:.68rem;"><?= htmlspecialchars($a['member_number']) ?></div>
                    </td>
                    <td style="font-size:.72rem;"><?= htmlspecialchars(ucfirst($a['account_type'])) ?> — <?= htmlspecialchars($a['account_number']) ?></td>
                    <td>
                        <span class="badge rounded-pill <?= $a['adjustment_type'] === 'credit' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?>">
                            <?= ucfirst($a['adjustment_type']) ?>
                        </span>
                    </td>
                    <td class="text-end fw-bold" style="font-variant-numeric:tabular-nums;">Shs <?= number_format($a['amount'], 0) ?></td>
                    <td class="text-center">
                        <span class="badge rounded-pill <?= match($a['status']){
                            'posted' => 'bg-success-subtle text-success',
                            'pending_approval' => 'bg-warning-subtle text-warning',
                            'approved' => 'bg-info-subtle text-info',
                            'rejected' => 'bg-danger-subtle text-danger',
                            default => 'bg-secondary-subtle text-secondary'
                        } ?>"><?= ucwords(str_replace('_', ' ', $a['status'])) ?></span>
                    </td>
                    <td class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars($a['recorded_by_name'] ?? '—') ?></td>
                    <td class="text-center">
                        <a href="<?= $base ?>?page=member-adjustment-view&id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
