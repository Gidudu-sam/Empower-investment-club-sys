<?php
$base = APP_URL . '/index.php';
$totalPages = max(1, (int)ceil($total / $limit));
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h4 mb-1 fw-bold text-gray-800"><i class="bi bi-list-check me-2 text-info"></i>Transaction Coverage Gaps</h1>
        <p class="text-muted mb-0 small">Transactions with no linked journal entry. Not automatically corruption — this project has a documented volume of dummy/test data seeded before the accounting-enforcement stage (Stage 24-28). Read-only.</p>
    </div>
    <a href="<?= $base ?>?page=investigation" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Search</a>
</div>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $type === 'loan_repayments' ? 'active' : '' ?>" href="<?= $base ?>?page=investigation-coverage&type=loan_repayments">Loan Repayments</a></li>
    <li class="nav-item"><a class="nav-link <?= $type === 'savings' ? 'active' : '' ?>" href="<?= $base ?>?page=investigation-coverage&type=savings">Savings Transactions</a></li>
</ul>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold">Results</h6>
        <span class="badge bg-secondary-subtle text-secondary"><?= $total ?> total</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Reference</th><th>Member</th><th>Date</th><th class="text-end">Amount</th><th>Created At</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($r['reference']) ?></td>
                    <td class="small"><?= htmlspecialchars($r['member']) ?></td>
                    <td class="small"><?= htmlspecialchars((string)$r['date']) ?></td>
                    <td class="text-end"><?= number_format($r['amount']) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars((string)$r['created_at']) ?></td>
                    <td class="text-end">
                        <a href="<?= $base ?>?page=<?= $r['route'] ?>&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Trace</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No coverage gaps in this category.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center small">
        <span>Page <?= $page ?> of <?= $totalPages ?></span>
        <div class="btn-group btn-group-sm">
            <?php if ($page > 1): ?><a class="btn btn-outline-secondary" href="?page=investigation-coverage&type=<?= $type ?>&p=<?= $page - 1 ?>">Previous</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a class="btn btn-outline-secondary" href="?page=investigation-coverage&type=<?= $type ?>&p=<?= $page + 1 ?>">Next</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="alert alert-secondary mt-3 small mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Click "Trace" to see full evidence for any individual record — its exact recorded timestamp, whether it predates the accounting engine, and whether a related journal exists under a different reference. This page does not judge or resolve anything by itself.
</div>
