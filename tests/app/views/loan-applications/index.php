<?php
$base = APP_URL . '/index.php';
function appBadge(string $s): string {
    return match($s) {
        'approved'         => 'bg-success-subtle text-success',
        'pending_approval' => 'bg-warning-subtle text-warning',
        'rejected'         => 'bg-danger-subtle text-danger',
        default            => 'bg-secondary-subtle text-secondary',
    };
}
function appStatusLabel(string $s): string {
    return $s === 'pending_approval' ? 'Pending Approval' : ucfirst($s);
}
// Matches LoanRoleAccessTrait exactly.
$canOriginate = Session::hasRole(['admin', 'loans_officer']);
$canApprove   = Session::hasRole(['admin', 'chairman']);
?>
<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800"><i class="bi bi-file-earmark-text me-2 text-warning"></i>Loan Applications</h1>
        <p class="text-muted mb-0 small">Requested terms, approved separately from the loan itself — an approved application can be converted into a loan from Record Loan.</p>
    </div>
    <?php if ($canOriginate): ?>
    <a href="<?= $base ?>?page=loan-application-add" class="btn btn-warning text-white">
        <i class="bi bi-plus-circle-fill me-1"></i>New Application
    </a>
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
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Application #</th>
                    <th>Member</th>
                    <th>Product</th>
                    <th class="text-end">Requested</th>
                    <th class="text-end">Approved</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($applications)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No loan applications recorded yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($applications as $a): ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($a['application_number']) ?></td>
                    <td><?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name']) ?> <span class="text-muted small">(<?= htmlspecialchars($a['member_number']) ?>)</span></td>
                    <td><?= htmlspecialchars($a['loan_type_name']) ?></td>
                    <td class="text-end">Shs <?= number_format((float)$a['requested_amount'], 2) ?></td>
                    <td class="text-end"><?= $a['approved_amount'] !== null ? 'Shs ' . number_format((float)$a['approved_amount'], 2) : '—' ?></td>
                    <td><span class="badge <?= appBadge($a['status']) ?>"><?= appStatusLabel($a['status']) ?></span></td>
                    <td class="text-end">
                        <a href="<?= $base ?>?page=loan-application-view&id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                        <?php if ($canOriginate && in_array($a['status'], ['draft','rejected'], true)): ?>
                        <a href="<?= $base ?>?page=loan-application-edit&id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <?php endif; ?>
                        <?php if ($canOriginate && $a['status'] === 'approved' && empty($a['converted_loan_id'])): ?>
                        <a href="<?= $base ?>?page=loan-convert-application&application_id=<?= $a['id'] ?>" class="btn btn-sm btn-success"><i class="bi bi-arrow-right-circle me-1"></i>Convert to Loan</a>
                        <?php elseif (!empty($a['converted_loan_id'])): ?>
                        <span class="badge bg-info-subtle text-info">Converted</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
