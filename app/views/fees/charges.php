<?php
$base = APP_URL . '/index.php';
// Matches FeeController::requireCollectAccess() / requireWaiveAccess() exactly.
$canCollectFee = Session::hasRole(['admin', 'treasurer', 'cashier', 'office_admin']);
$canWaiveFee = Session::hasRole(['admin', 'treasurer']);
// Matches FeeController::index()'s requireAdmin() exactly -- this link was
// previously shown to everyone, redirecting non-admin roles straight back
// to the dashboard with no explanation (the "Fees" sidebar link had the
// identical bug, fixed alongside this).
$canManageFeeTypes = Session::hasRole(['admin']);
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Member Fee Charges</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">All fees charged to members</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canCollectFee): ?>
        <a href="<?= $base ?>?page=fee-charge-form" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Record Fee</a>
        <?php endif; ?>
        <?php if ($canManageFeeTypes): ?>
        <a href="<?= $base ?>?page=fees" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear me-1"></i>Manage Fees</a>
        <?php endif; ?>
        <a href="<?= $base ?>?page=fee-report" class="btn btn-outline-primary btn-sm"><i class="bi bi-bar-chart me-1"></i>Reports</a>
    </div>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" action="<?= $base ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="fee-charges">
            <div class="col-md-3">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search member or reference..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="waived" <?= $status === 'waived' ? 'selected' : '' ?>>Waived</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="fee_id" class="form-select form-select-sm">
                    <option value="">All Fees</option>
                    <?php foreach ($fees as $f): ?>
                    <option value="<?= $f['id'] ?>" <?= $feeId === (int)$f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['fee_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
            </div>
            <div class="col-auto">
                <a href="<?= $base ?>?page=fee-charges" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Charges Table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold" style="font-size:.82rem;">Charge Ledger</h6>
        <span class="badge bg-primary-subtle text-primary"><?= number_format($total) ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Reference</th>
                    <th>Member</th>
                    <th>Fee</th>
                    <th class="text-end">Amount</th>
                    <th class="text-center">Status</th>
                    <th>Charged</th>
                    <th>Paid</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($charges)): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">No charges found.</td></tr>
                <?php else: foreach ($charges as $c): ?>
                <tr>
                    <td class="ps-3 fw-semibold" style="font-size:.72rem;"><?= htmlspecialchars($c['reference_number'] ?? '—') ?></td>
                    <td class="fw-semibold" style="font-size:.78rem;"><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></td>
                    <td style="font-size:.72rem;"><?= htmlspecialchars($c['fee_name']) ?></td>
                    <td class="text-end fw-bold" style="font-variant-numeric:tabular-nums;">UGX <?= number_format($c['amount'], 0) ?></td>
                    <td class="text-center">
                        <span class="badge rounded-pill <?= match($c['status']){
                            'paid' => 'bg-success-subtle text-success',
                            'pending' => 'bg-warning-subtle text-warning',
                            'waived' => 'bg-info-subtle text-info',
                            'cancelled' => 'bg-secondary-subtle text-secondary',
                            default => 'bg-secondary-subtle text-secondary'
                        } ?>"><?= ucfirst($c['status']) ?></span>
                    </td>
                    <td class="text-muted" style="font-size:.72rem;"><?= date('d M Y', strtotime($c['charged_date'])) ?></td>
                    <td class="text-muted" style="font-size:.72rem;"><?= $c['paid_date'] ? date('d M Y', strtotime($c['paid_date'])) : '—' ?></td>
                    <td class="text-center">
                        <?php if ($c['status'] === 'pending' && ($canCollectFee || $canWaiveFee)): ?>
                        <div class="btn-group btn-group-sm">
                            <?php if ($canCollectFee): ?>
                            <button type="button" class="btn btn-outline-success" title="Mark Paid"
                                    onclick="openMarkPaidModal(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(addslashes($c['reference_number'] ?? ''), ENT_QUOTES) ?>')">
                                <i class="bi bi-check"></i>
                            </button>
                            <?php endif; ?>
                            <?php if ($canWaiveFee): ?>
                            <form method="POST" action="<?= $base ?>?page=fee-mark-waived" style="display:contents" onsubmit="return confirm('Waive this fee?')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-outline-info" title="Waive"><i class="bi bi-slash-circle"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php elseif ($c['status'] === 'pending'): ?>
                        <span class="text-muted">—</span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
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
        <a class="page-link" href="<?= $base ?>?page=fee-charges&p=<?= $p ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&fee_id=<?= $feeId ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<!-- Mark Paid Modal -->
<div class="modal fade" id="markPaidModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?= $base ?>?page=fee-mark-paid">
                <div class="modal-header">
                    <h6 class="modal-title fw-semibold">Mark Fee as Paid</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="id" id="markPaidId" value="">
                    <p class="mb-2" style="font-size:.8rem;">Reference: <strong id="markPaidRef"></strong></p>
                    <label class="form-label" style="font-size:.78rem;">Payment Method</label>
                    <select name="payment_method" class="form-select form-select-sm" required>
                        <option value="">Select method...</option>
                        <option value="Cash">Cash</option>
                        <option value="MTN Mobile Money">MTN Mobile Money</option>
                        <option value="Airtel Money">Airtel Money</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Cheque">Cheque</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm">Confirm Paid</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openMarkPaidModal(id, ref) {
    document.getElementById('markPaidId').value = id;
    document.getElementById('markPaidRef').textContent = ref;
    var modal = new bootstrap.Modal(document.getElementById('markPaidModal'));
    modal.show();
}
</script>
