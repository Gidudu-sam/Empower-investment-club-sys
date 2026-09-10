<?php
$base = APP_URL . '/index.php';
function pendingApprovalDaysLabel(int $d): string {
    if ($d === 0) return '<span class="text-muted small">Today</span>';
    if ($d === 1) return '<span class="badge bg-info text-dark rounded-pill">1 day</span>';
    if ($d <= 3)  return '<span class="badge bg-info text-dark rounded-pill">' . $d . ' days</span>';
    if ($d <= 7)  return '<span class="badge bg-warning text-dark rounded-pill">' . $d . ' days</span>';
    return '<span class="badge bg-danger rounded-pill">' . $d . ' days</span>';
}
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-hourglass-split me-2 text-warning"></i>Loans Awaiting Approval
        </h1>
        <p class="text-muted mb-0 small">Loan applications submitted and waiting on a decision</p>
    </div>
</div>

<!-- Alerts -->
<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i>
    <div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
    <div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Approval funnel stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-warning">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-warning"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="stat-label">Pending Approval</div>
                    <div class="stat-value"><?= number_format($pendingCount) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-success">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-check-circle-fill"></i></div>
                <div>
                    <div class="stat-label">Approved This Month</div>
                    <div class="stat-value"><?= number_format($approvedCount) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-4">
        <div class="stat-card stat-card-danger">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-danger"><i class="bi bi-x-circle-fill"></i></div>
                <div>
                    <div class="stat-label">Rejected This Month</div>
                    <div class="stat-value"><?= number_format($rejectedCount) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Table card -->
<div class="card">
    <div class="card-header">
        <form id="filterForm" method="GET" action="<?= $base ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="loan-pending-approval">

            <div class="col-lg-5 col-md-6">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" id="searchInput" class="form-control"
                           placeholder="Loan no., member name, number, loan officer…"
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>

            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-search me-1"></i>Search
                </button>
                <?php if ($search): ?>
                <a href="<?= $base ?>?page=loan-pending-approval" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-lg me-1"></i>Clear
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Loan No.</th>
                    <th>Member / Account</th>
                    <th class="d-none d-md-table-cell">Loan Type</th>
                    <th class="text-end">Requested Amount</th>
                    <th class="d-none d-lg-table-cell">Submitted</th>
                    <th class="d-none d-lg-table-cell">Submitted By</th>
                    <th class="text-center">Waiting</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($loans)): ?>
                <tr><td colspan="8" class="text-center py-5 text-muted">
                    <i class="bi bi-bank2 fs-1 d-block mb-2 opacity-25"></i>
                    <?php if ($search): ?>
                        No pending loans match your search.
                    <?php else: ?>
                        Nothing awaiting your approval right now.
                    <?php endif; ?>
                </td></tr>
                <?php else: foreach ($loans as $l):
                    $daysWaiting = (int)($l['days_waiting'] ?? 0);
                    $submittedAt = $l['submitted_at'] ?? $l['created_at'];
                ?>
                <tr>
                    <td class="ps-3">
                        <a href="<?= $base ?>?page=loan-view&id=<?= $l['id'] ?>"
                           class="fw-semibold text-decoration-none" style="color:var(--brand-navy)">
                            <?= htmlspecialchars($l['loan_number']) ?>
                        </a>
                    </td>
                    <td>
                        <a href="<?= $base ?>?page=member-view&id=<?= $l['member_id'] ?>"
                           class="text-decoration-none fw-semibold">
                            <?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?>
                        </a>
                        <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($l['member_number']) ?></div>
                    </td>
                    <td class="d-none d-md-table-cell small text-muted">
                        <?= htmlspecialchars($l['loan_type_name'] ?? '—') ?>
                    </td>
                    <td class="text-end fw-semibold small">Shs <?= number_format($l['loan_amount'], 2) ?></td>
                    <td class="d-none d-lg-table-cell text-muted small">
                        <?= $submittedAt ? date('d M Y', strtotime($submittedAt)) : '—' ?>
                    </td>
                    <td class="d-none d-lg-table-cell small">
                        <?= htmlspecialchars($l['loan_officer'] ?: ($l['recorded_by_name'] ?? '—')) ?>
                    </td>
                    <td class="text-center"><?= pendingApprovalDaysLabel($daysWaiting) ?></td>
                    <td class="text-end pe-3">
                        <a href="<?= $base ?>?page=loan-view&id=<?= $l['id'] ?>"
                           class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye me-1"></i>Review
                        </a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="text-muted small">Page <?= $currentPage ?> of <?= $pages ?> &middot; <?= number_format($total) ?> records</div>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $base ?>?page=loan-pending-approval&p=<?= $currentPage - 1 ?>&search=<?= urlencode($search) ?>"><i class="bi bi-chevron-left"></i></a>
            </li>
            <?php for ($pg = 1; $pg <= $pages; $pg++): ?>
            <li class="page-item <?= $pg === $currentPage ? 'active' : '' ?>">
                <a class="page-link" href="<?= $base ?>?page=loan-pending-approval&p=<?= $pg ?>&search=<?= urlencode($search) ?>"><?= $pg ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $currentPage >= $pages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $base ?>?page=loan-pending-approval&p=<?= $currentPage + 1 ?>&search=<?= urlencode($search) ?>"><i class="bi bi-chevron-right"></i></a>
            </li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>
