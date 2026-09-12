<?php
$pageTitle = $pageTitle ?? 'Balance Brought Forward Register';
$base = APP_URL . '/index.php';

$statusBadge = function (array $row): string {
    if ($row['bf_posting_mode'] === null) {
        return '<span class="badge rounded-pill bg-warning-subtle text-warning">Unclassified</span>';
    }
    if ($row['bf_posting_mode'] === 'historical_only') {
        return '<span class="badge rounded-pill bg-secondary-subtle text-secondary">Historical Only</span>';
    }
    $label = 'Verified ' . htmlspecialchars($row['asset_account_name'] ?? 'Asset');
    return '<span class="badge rounded-pill bg-success-subtle text-success">' . $label . '</span>';
};
?>

<?php require VIEW_PATH . '/layouts/page-title.php'; ?>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div><i class="bi bi-clock-history me-2"></i>Balance Brought Forward Register</div>
        <div class="btn-group btn-group-sm">
            <a href="<?= $base ?>?page=savings-account-bf-register" class="btn btn-outline-secondary <?= $statusFilter === null ? 'active' : '' ?>">All</a>
            <a href="<?= $base ?>?page=savings-account-bf-register&status=unclassified" class="btn btn-outline-warning <?= $statusFilter === 'unclassified' ? 'active' : '' ?>">Unclassified</a>
            <a href="<?= $base ?>?page=savings-account-bf-register&status=classified" class="btn btn-outline-success <?= $statusFilter === 'classified' ? 'active' : '' ?>">Classified</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Receipt</th>
                    <th>Member</th>
                    <th>Account</th>
                    <th class="text-end">Amount</th>
                    <th>Effective Date</th>
                    <th>Status</th>
                    <th>Recorded By</th>
                    <th class="text-center pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">No Balance Brought Forward records found.</td></tr>
                <?php else: foreach ($rows as $row): ?>
                <tr>
                    <td class="ps-3 fw-semibold" style="font-size:.78rem;"><?= htmlspecialchars($row['receipt_number']) ?></td>
                    <td style="font-size:.82rem;"><?= htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?: '—' ?><br><span class="text-muted small"><?= htmlspecialchars($row['member_number'] ?? '') ?></span></td>
                    <td style="font-size:.82rem;"><?= htmlspecialchars($row['account_number'] ?? '') ?> <span class="text-muted text-capitalize small">(<?= htmlspecialchars($row['account_type'] ?? '') ?>)</span></td>
                    <td class="text-end fw-bold" style="font-variant-numeric:tabular-nums;">Shs <?= number_format((float)$row['credit'], 2) ?></td>
                    <td style="font-size:.82rem;"><?= date('d M Y', strtotime($row['transaction_date'])) ?></td>
                    <td><?= $statusBadge($row) ?></td>
                    <td style="font-size:.78rem;" class="text-muted"><?= htmlspecialchars($row['recorded_by_name'] ?? ('user #' . $row['recorded_by'])) ?></td>
                    <td class="text-center pe-3">
                        <?php if ($row['bf_posting_mode'] === null): ?>
                        <a href="<?= $base ?>?page=savings-account-bf-classify&id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-primary">Review &amp; Classify</a>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
