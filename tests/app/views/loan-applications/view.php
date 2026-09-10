<?php
$base = APP_URL . '/index.php';
$canOriginate = Session::hasRole(['admin', 'loans_officer']);
// Stage 23: matches LoanApplicationController's own overridden
// requireApproverAccess() exactly -- Secretary + Vice Chairman both gain
// loan-application approval authority (a status/data decision only, never
// a funds movement), per management's Stage 23 governance decision.
$canApprove   = Session::hasRole(['admin', 'chairman', 'vice_chairman', 'secretary']);
$a = $application;
function appBadge2(string $s): string {
    return match($s) {
        'approved'         => 'bg-success-subtle text-success',
        'pending_approval' => 'bg-warning-subtle text-warning',
        'rejected'         => 'bg-danger-subtle text-danger',
        default            => 'bg-secondary-subtle text-secondary',
    };
}
?>
<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800"><i class="bi bi-file-earmark-text me-2 text-warning"></i><?= htmlspecialchars($a['application_number']) ?></h1>
        <span class="badge <?= appBadge2($a['status']) ?>"><?= $a['status'] === 'pending_approval' ? 'Pending Approval' : ucfirst($a['status']) ?></span>
    </div>
    <a href="<?= $base ?>?page=loan-applications" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header fw-semibold">Application Details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Member</dt><dd class="col-sm-8"><?= htmlspecialchars($a['first_name'].' '.$a['last_name']) ?> (<?= htmlspecialchars($a['member_number']) ?>)</dd>
                    <dt class="col-sm-4">Product</dt><dd class="col-sm-8"><?= htmlspecialchars($a['loan_type_name']) ?></dd>
                    <dt class="col-sm-4">Requested Amount</dt><dd class="col-sm-8">Shs <?= number_format((float)$a['requested_amount'], 2) ?></dd>
                    <dt class="col-sm-4">Requested Period</dt><dd class="col-sm-8"><?= (int)$a['requested_period_months'] ?> month(s)</dd>
                    <?php if ($a['approved_amount'] !== null): ?>
                    <dt class="col-sm-4">Approved Amount</dt><dd class="col-sm-8 fw-semibold text-success">Shs <?= number_format((float)$a['approved_amount'], 2) ?></dd>
                    <dt class="col-sm-4">Approved Period</dt><dd class="col-sm-8 fw-semibold text-success"><?= (int)$a['approved_period_months'] ?> month(s)</dd>
                    <?php endif; ?>
                    <dt class="col-sm-4">Purpose</dt><dd class="col-sm-8"><?= nl2br(htmlspecialchars($a['purpose'] ?? '—')) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">Eligibility Information</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Income Source</dt><dd class="col-sm-8"><?= htmlspecialchars($a['income_source'] ?? '—') ?> <?= $a['income_details'] ? '— ' . htmlspecialchars($a['income_details']) : '' ?></dd>
                    <dt class="col-sm-4">Asset Purchase Price</dt><dd class="col-sm-8"><?= $a['asset_purchase_price'] !== null ? 'Shs ' . number_format((float)$a['asset_purchase_price'], 2) : '—' ?></dd>
                    <dt class="col-sm-4">Member Contribution</dt><dd class="col-sm-8"><?= $a['member_contribution'] !== null ? 'Shs ' . number_format((float)$a['member_contribution'], 2) : '—' ?></dd>
                    <dt class="col-sm-4">Security</dt><dd class="col-sm-8"><?= htmlspecialchars($a['security_type'] ?? '—') ?> <?= $a['security_description'] ? '— ' . htmlspecialchars($a['security_description']) : '' ?></dd>
                    <dt class="col-sm-4">Weekly Savings Commitment</dt><dd class="col-sm-8"><?= $a['weekly_savings_commitment'] !== null ? 'Shs ' . number_format((float)$a['weekly_savings_commitment'], 2) : '—' ?></dd>
                </dl>
            </div>
        </div>

        <?php if (!empty($a['rejection_reason'])): ?>
        <div class="alert alert-danger"><strong>Rejection reason:</strong> <?= htmlspecialchars($a['rejection_reason']) ?></div>
        <?php endif; ?>

        <?php if (!empty($a['converted_loan_number'])): ?>
        <div class="alert alert-info">
            Converted to loan <a href="<?= $base ?>?page=loan-view&id=<?= (int)$a['converted_loan_id'] ?>"><?= htmlspecialchars($a['converted_loan_number']) ?></a>.
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold">Actions</div>
            <div class="card-body d-grid gap-2">
                <?php if ($canOriginate && $a['status'] === 'draft'): ?>
                <form method="POST" action="<?= $base ?>?page=loan-application-submit">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="application_id" value="<?= (int)$a['id'] ?>">
                    <button type="submit" class="btn btn-warning text-white w-100"><i class="bi bi-send me-1"></i>Submit for Approval</button>
                </form>
                <a href="<?= $base ?>?page=loan-application-edit&id=<?= (int)$a['id'] ?>" class="btn btn-outline-secondary w-100"><i class="bi bi-pencil me-1"></i>Edit</a>
                <?php endif; ?>

                <?php if ($canApprove && $a['status'] === 'pending_approval'): ?>
                <form method="POST" action="<?= $base ?>?page=loan-application-approve" class="border rounded p-2 mb-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="application_id" value="<?= (int)$a['id'] ?>">
                    <label class="form-label small fw-semibold mb-1">Approved Amount (Shs)</label>
                    <input type="number" name="approved_amount" class="form-control form-control-sm mb-2" step="1" min="1" value="<?= htmlspecialchars((string)$a['requested_amount']) ?>" required>
                    <label class="form-label small fw-semibold mb-1">Approved Period (months)</label>
                    <input type="number" name="approved_period_months" class="form-control form-control-sm mb-2" step="1" min="1" value="<?= (int)$a['requested_period_months'] ?>" required>
                    <button type="submit" class="btn btn-success w-100 btn-sm"><i class="bi bi-check-circle me-1"></i>Approve</button>
                </form>
                <form method="POST" action="<?= $base ?>?page=loan-application-reject" class="border rounded p-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="application_id" value="<?= (int)$a['id'] ?>">
                    <label class="form-label small fw-semibold mb-1">Rejection Reason</label>
                    <textarea name="reason" class="form-control form-control-sm mb-2" rows="2" required></textarea>
                    <button type="submit" class="btn btn-outline-danger w-100 btn-sm"><i class="bi bi-x-circle me-1"></i>Reject</button>
                </form>
                <?php endif; ?>

                <?php if ($canOriginate && $a['status'] === 'approved' && empty($a['converted_loan_id'])): ?>
                <a href="<?= $base ?>?page=loan-convert-application&application_id=<?= (int)$a['id'] ?>" class="btn btn-success w-100"><i class="bi bi-arrow-right-circle me-1"></i>Convert to Loan</a>
                <?php endif; ?>

                <?php if ($a['status'] === 'pending_approval'): ?>
                <p class="text-muted small mb-0">Awaiting a Chairman or Admin decision. The person who recorded this application cannot approve it themselves.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
