<?php $base = APP_URL . '/index.php'; ?>
<h3 class="fw-bold mb-3"><i class="bi bi-cash-coin me-2"></i>My Loans</h3>

<?php if ($activeLoan): ?>
<div class="card mb-4 border-success">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <div class="text-muted small">Active Loan</div>
                <div class="fw-bold fs-5"><?= htmlspecialchars($activeLoan['loan_number']) ?></div>
                <div class="text-muted small">Due <?= !empty($activeLoan['due_date']) ? date('d M Y', strtotime($activeLoan['due_date'])) : '—' ?></div>
            </div>
            <div class="text-end">
                <div class="text-muted small">Outstanding</div>
                <div class="fw-bold fs-5 text-danger">Shs <?= number_format((float)$activeLoan['outstanding'], 2) ?></div>
            </div>
            <a href="<?= $base ?>?page=portal-loan-view&id=<?= $activeLoan['id'] ?>" class="btn btn-outline-success btn-sm align-self-center">
                View Schedule <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="stat-card"><div class="stat-label">Total Loans</div><div class="stat-value" style="font-size:1.2rem"><?= (int)($summary['total_loans'] ?? count($history)) ?></div></div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card"><div class="stat-label">Completed</div><div class="stat-value" style="font-size:1.2rem"><?= (int)($summary['completed'] ?? 0) ?></div></div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card"><div class="stat-label">Total Borrowed</div><div class="stat-value" style="font-size:1.2rem">Shs <?= number_format((float)($summary['total_borrowed'] ?? 0), 2) ?></div></div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0 fw-semibold">Loan History</h6></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Loan Number</th><th>Type</th><th class="text-end">Amount</th><th class="text-end">Outstanding</th><th class="text-center">Status</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($history)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No loans on record.</td></tr>
                <?php else: foreach ($history as $l): ?>
                <tr>
                    <td><?= htmlspecialchars($l['loan_number']) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($l['loan_type_name'] ?? '—') ?></td>
                    <td class="text-end">Shs <?= number_format((float)$l['loan_amount'], 2) ?></td>
                    <td class="text-end">Shs <?= number_format((float)$l['outstanding'], 2) ?></td>
                    <td class="text-center">
                        <span class="badge rounded-pill bg-secondary-subtle text-secondary text-capitalize"><?= htmlspecialchars(str_replace('_',' ',$l['status'])) ?></span>
                    </td>
                    <td class="text-end">
                        <a href="<?= $base ?>?page=portal-loan-view&id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
