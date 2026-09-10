<?php
$base = APP_URL . '/index.php';
$isWithdrawal = ($saving['transaction_type'] ?? 'deposit') === 'withdrawal';
function savBadgeV(string $m): string {
    return match($m){
        'Cash'=>'bg-success-subtle text-success','Airtel Money'=>'bg-danger-subtle text-danger','MTN Mobile Money'=>'bg-warning-subtle text-warning',
        'Bank Transfer'=>'bg-info-subtle text-info','Cheque'=>'bg-warning-subtle text-warning',
        default=>'bg-secondary-subtle text-secondary'
    };
}
// Matches SavingsController::edit() / delete() exactly.
// Stage 13-F3: a posted (journal-linked) row is immutable at the
// model layer regardless of this UI check -- this is defense-in-depth
// only, so the Edit/Delete buttons don't invite an action the backend
// will now always reject.
$isPostedSaving = !empty($saving['journal_entry_id']);
$canEditSavings = Session::hasRole(['admin', 'treasurer']) && !$isPostedSaving;
$canDeleteSavings = Session::hasRole(['admin']) && !$isPostedSaving;
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-receipt me-2 text-success"></i><?= $isWithdrawal ? 'Withdrawal Record' : 'Savings Record' ?>
        </h1>
        <p class="text-muted mb-0 small"><?= htmlspecialchars($saving['receipt_number']) ?></p>
    </div>
    <a href="<?= $base ?>?page=savings" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Savings
    </a>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- Main receipt card -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-receipt me-2 text-success"></i>Receipt Details
                </h6>
                <div class="d-flex gap-2">
                    <a href="<?= $base ?>?page=savings-receipt&id=<?= $saving['id'] ?>"
                       class="btn btn-sm btn-success" target="_blank">
                        <i class="bi bi-printer me-1"></i>Print Receipt
                    </a>
                    <?php if ($canEditSavings): ?>
                    <a href="<?= $base ?>?page=savings-edit&id=<?= $saving['id'] ?>"
                       class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="detail-label">Receipt Number</div>
                        <div class="detail-value fw-bold text-success fs-5"><?= htmlspecialchars($saving['receipt_number']) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="detail-label">Amount</div>
                        <div class="detail-value fw-bold text-success fs-4">Shs <?= number_format($saving['amount_abs'] ?? abs($saving['amount']), 2) ?></div>
                    </div>
                    <?php if (!empty($saving['account_number'])): ?>
                    <div class="col-sm-6">
                        <div class="detail-label">Savings Account</div>
                        <div class="detail-value">
                            <a href="<?= $base ?>?page=savings-account-view&id=<?= (int)$saving['savings_account_id'] ?>" class="text-decoration-none">
                                <?= htmlspecialchars($saving['account_number']) ?> <span class="text-muted">(<?= htmlspecialchars(ucfirst($saving['account_type'])) ?>)</span>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-sm-6">
                        <div class="detail-label">Payment Method</div>
                        <div class="detail-value">
                            <span class="badge <?= savBadgeV($saving['payment_method']) ?> rounded-pill px-3 py-2">
                                <?= htmlspecialchars($saving['payment_method']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="detail-label">Reference Number</div>
                        <div class="detail-value">
                            <?= $saving['reference_number'] ? htmlspecialchars($saving['reference_number']) : '—' ?>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="detail-label">Transaction Date</div>
                        <div class="detail-value"><?= date('d F Y', strtotime($saving['transaction_date'])) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="detail-label">Financial Year</div>
                        <div class="detail-value"><?= htmlspecialchars($saving['financial_year']) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="detail-label">Journal Reference</div>
                        <div class="detail-value">
                            <?php if (!empty($saving['entry_number'])): ?>
                                <a href="<?= $base ?>?page=report-general-ledger&account_id=17" class="badge bg-success-subtle text-success text-decoration-none">
                                    <?= htmlspecialchars($saving['entry_number']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">Not yet posted</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if($saving['notes']): ?>
                    <div class="col-12">
                        <div class="detail-label">Notes</div>
                        <div class="detail-value text-muted"><?= nl2br(htmlspecialchars($saving['notes'])) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="col-sm-6">
                        <div class="detail-label">Recorded By</div>
                        <div class="detail-value"><?= htmlspecialchars($saving['cashier_name'] ?? '—') ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="detail-label">Recorded On</div>
                        <div class="detail-value text-muted small">
                            <?= date('d M Y, H:i', strtotime($saving['created_at'])) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Member + action panel -->
    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-person-circle text-primary"></i>
                <h6 class="mb-0 fw-semibold">Member</h6>
            </div>
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="member-avatar-lg bg-blue" style="width:60px;height:60px;font-size:1.4rem;">
                        <?= strtoupper(substr($saving['first_name'],0,1)) ?>
                    </div>
                    <div>
                        <div class="fw-bold fs-6">
                            <?= htmlspecialchars($saving['first_name'].' '.$saving['last_name']) ?>
                        </div>
                        <div class="text-muted small"><?= htmlspecialchars($saving['member_number']) ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($saving['member_phone'] ?? '') ?></div>
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="<?= $base ?>?page=member-view&id=<?= $saving['member_id'] ?>"
                       class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-person me-1"></i>View Profile
                    </a>
                    <a href="<?= $base ?>?page=savings-member&member_id=<?= $saving['member_id'] ?>"
                       class="btn btn-sm btn-outline-success">
                        <i class="bi bi-clock-history me-1"></i>Savings History
                    </a>
                    <a href="<?= $base ?>?page=savings-add&member_id=<?= $saving['member_id'] ?>"
                       class="btn btn-sm btn-success">
                        <i class="bi bi-plus me-1"></i>New Deposit
                    </a>
                </div>
            </div>
        </div>

        <!-- Danger zone -->
        <?php if ($canDeleteSavings): ?>
        <div class="card border-danger border-opacity-25">
            <div class="card-header bg-danger bg-opacity-10">
                <h6 class="mb-0 fw-semibold text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Danger Zone</h6>
            </div>
            <div class="card-body p-3">
                <p class="small text-muted mb-3">Deleting this record is permanent and cannot be undone.</p>
                <button type="button" class="btn btn-outline-danger btn-sm w-100" id="openDeleteBtn">
                    <i class="bi bi-trash me-1"></i>Delete This Record
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canDeleteSavings): ?>
<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4">
                <p class="mb-1 text-muted small">Permanently delete:</p>
                <p class="fw-bold mb-0"><?= htmlspecialchars($saving['receipt_number']) ?> — Shs <?= number_format($saving['amount_abs'] ?? abs($saving['amount']),2) ?></p>
                <p class="text-danger small mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>This cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= APP_URL ?>/index.php?page=savings-delete" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= $saving['id'] ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('openDeleteBtn').addEventListener('click',function(){
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
});
</script>
<?php endif; ?>
