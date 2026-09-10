<?php
$base = APP_URL . '/index.php';
function mlBadge(string $s): string {
    return match($s){
        'active'=>'bg-success-subtle text-success','completed'=>'bg-primary-subtle text-primary',
        'overdue'=>'bg-danger-subtle text-danger',default=>'bg-secondary-subtle text-secondary'
    };
}
?>

<?php ob_start(); ?>
<a href="<?= $base ?>?page=member-view&id=<?= $member['id'] ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-person me-1"></i>Profile
</a>
<a href="<?= $base ?>?page=loan-add&member_id=<?= $member['id'] ?>" class="btn btn-warning text-white btn-sm">
    <i class="bi bi-plus me-1"></i>New Loan
</a>
<?php $cta = ob_get_clean(); ?>
<?php extract([
    'icon'     => 'bi-bank2 text-warning',
    'title'    => 'Loan History',
    'subtitle' => htmlspecialchars($member['first_name'].' '.$member['last_name']) . ' · ' . htmlspecialchars($member['member_number']),
    'cta'      => $cta,
]); include VIEW_PATH . '/layouts/page-title.php'; ?>

<!-- Active loan highlight -->
<?php if ($activeLoan): ?>
<div class="card mb-4 border-warning border-opacity-50">
    <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center gap-2">
        <i class="bi bi-bank2 text-warning"></i>
        <h6 class="mb-0 fw-semibold text-warning">Current Active Loan</h6>
    </div>
    <div class="card-body p-4">
        <div class="row g-3">
            <div class="col-sm-3">
                <div class="detail-label">Loan Number</div>
                <div class="detail-value fw-bold" style="color:var(--brand-navy)"><?= htmlspecialchars($activeLoan['loan_number']) ?></div>
            </div>
            <div class="col-sm-3">
                <div class="detail-label">Loan Amount</div>
                <div class="detail-value fw-bold">Shs <?= number_format($activeLoan['loan_amount'],2) ?></div>
            </div>
            <div class="col-sm-3">
                <div class="detail-label">Outstanding</div>
                <div class="detail-value fw-bold text-danger">Shs <?= number_format($activeLoan['outstanding'],2) ?></div>
            </div>
            <div class="col-sm-3">
                <div class="detail-label">Due Date</div>
                <div class="detail-value"><?= date('d M Y', strtotime($activeLoan['due_date'])) ?></div>
            </div>
            <div class="col-12 pt-0">
                <a href="<?= $base ?>?page=loan-view&id=<?= $activeLoan['id'] ?>" class="btn btn-sm btn-warning text-white">
                    <i class="bi bi-eye me-1"></i>View Full Details
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- History table -->
<div class="card">
    <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-clock-history me-2 text-warning"></i>All Loans</h6></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Loan No.</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end">Outstanding</th>
                    <th>Issue Date</th>
                    <th>Due Date</th>
                    <th class="text-center">Status</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">
                    No loans recorded yet.
                    <a href="<?= $base ?>?page=loan-add&member_id=<?= $member['id'] ?>">Record a loan.</a>
                </td></tr>
                <?php else: foreach($history as $l): ?>
                <tr>
                    <td class="ps-3">
                        <a href="<?= $base ?>?page=loan-view&id=<?= $l['id'] ?>"
                           class="fw-semibold text-decoration-none" style="color:var(--brand-navy)">
                            <?= htmlspecialchars($l['loan_number']) ?>
                        </a>
                    </td>
                    <td class="text-end small">Shs <?= number_format($l['loan_amount'],2) ?></td>
                    <td class="text-end fw-bold small">Shs <?= number_format($l['outstanding'],2) ?></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($l['issue_date'])) ?></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($l['due_date'])) ?></td>
                    <td class="text-center">
                        <span class="badge <?= mlBadge($l['status']) ?> rounded-pill px-2"><?= ucfirst($l['status']) ?></span>
                    </td>
                    <td class="text-end pe-3">
                        <a href="<?= $base ?>?page=loan-view&id=<?= $l['id'] ?>"
                           class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
