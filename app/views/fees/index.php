<?php $base = APP_URL . '/index.php'; ?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Fees & Charges</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">Configure and manage all club fees</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $base ?>?page=fee-charges" class="btn btn-outline-primary btn-sm"><i class="bi bi-list-check me-1"></i>View Charges</a>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#feeModal" onclick="resetFeeForm()">
            <i class="bi bi-plus-circle me-1"></i>Add Fee
        </button>
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

<!-- Fees Table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold" style="font-size:.82rem;">Configured Fees</h6>
        <span class="badge bg-primary-subtle text-primary"><?= count($fees) ?> fees</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Fee Name</th>
                    <th>Type</th>
                    <th class="text-end">Amount / %</th>
                    <th>Frequency</th>
                    <th class="text-center">Status</th>
                    <th>Effective Date</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($fees)): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">No fees configured.</td></tr>
                <?php else: foreach ($fees as $f): ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?= htmlspecialchars($f['fee_name']) ?></td>
                    <td><span class="badge bg-secondary-subtle text-secondary"><?= ucfirst($f['fee_type']) ?></span></td>
                    <td class="text-end fw-bold" style="font-variant-numeric:tabular-nums;">
                        <?php if ($f['fee_type'] === 'percentage'): ?>
                            <?= number_format($f['amount'], 1) ?>%
                        <?php else: ?>
                            UGX <?= number_format($f['amount'], 0) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted" style="font-size:.72rem;"><?= ucwords(str_replace('_', ' ', $f['frequency'])) ?></td>
                    <td class="text-center">
                        <span class="badge rounded-pill <?= $f['is_active'] ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>">
                            <?= $f['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td class="text-muted" style="font-size:.72rem;"><?= $f['effective_date'] ? date('d M Y', strtotime($f['effective_date'])) : '—' ?></td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" title="Edit" onclick='editFee(<?= json_encode($f) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="<?= $base ?>?page=fee-toggle" style="display:contents">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= $f['id'] ?>">
                                <button type="submit" class="btn btn-outline-<?= $f['is_active'] ? 'warning' : 'success' ?>" title="<?= $f['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                    <i class="bi bi-<?= $f['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                </button>
                            </form>
                            <form method="POST" action="<?= $base ?>?page=fee-delete" style="display:contents" onsubmit="return confirm('Delete this fee? Only works if never charged.')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= $f['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Fee Modal -->
<div class="modal fade" id="feeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= $base ?>?page=fee-save">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="id" id="feeId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="feeModalTitle"><i class="bi bi-plus-circle me-2"></i>Add Fee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Fee Name *</label>
                        <input type="text" name="fee_name" id="feeName" class="form-control" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Fee Type *</label>
                            <select name="fee_type" id="feeType" class="form-select" required>
                                <option value="fixed">Fixed Amount</option>
                                <option value="percentage">Percentage</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Amount / Percentage *</label>
                            <input type="number" name="amount" id="feeAmount" class="form-control" min="0" step="0.01" required>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Frequency *</label>
                            <select name="frequency" id="feeFrequency" class="form-select" required>
                                <option value="one_time">One Time</option>
                                <option value="annual">Annual</option>
                                <option value="per_loan">Per Loan</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Effective Date</label>
                            <input type="date" name="effective_date" id="feeDate" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="mb-3 mt-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" id="feeDesc" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="feeActive" class="form-check-input" value="1" checked>
                        <label class="form-check-label" for="feeActive">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Save Fee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetFeeForm() {
    document.getElementById('feeId').value = '0';
    document.getElementById('feeName').value = '';
    document.getElementById('feeType').value = 'fixed';
    document.getElementById('feeAmount').value = '';
    document.getElementById('feeFrequency').value = 'one_time';
    document.getElementById('feeDate').value = '<?= date('Y-m-d') ?>';
    document.getElementById('feeDesc').value = '';
    document.getElementById('feeActive').checked = true;
    document.getElementById('feeModalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Add Fee';
}

function editFee(fee) {
    document.getElementById('feeId').value = fee.id;
    document.getElementById('feeName').value = fee.fee_name;
    document.getElementById('feeType').value = fee.fee_type;
    document.getElementById('feeAmount').value = fee.amount;
    document.getElementById('feeFrequency').value = fee.frequency;
    document.getElementById('feeDate').value = fee.effective_date || '';
    document.getElementById('feeDesc').value = fee.description || '';
    document.getElementById('feeActive').checked = fee.is_active == 1;
    document.getElementById('feeModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Fee';
    new bootstrap.Modal(document.getElementById('feeModal')).show();
}
</script>
