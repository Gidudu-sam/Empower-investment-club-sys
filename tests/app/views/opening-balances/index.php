<?php
$pageTitle = $pageTitle ?? 'Opening Balances';
$title = $pageTitle;
$icon  = 'bi-clipboard2-check';

function obStatusBadge(string $status): string {
    $map = [
        'draft'            => 'bg-secondary',
        'pending_approval'  => 'bg-warning text-dark',
        'approved'         => 'bg-info text-dark',
        'posted'           => 'bg-success',
        'rejected'         => 'bg-danger',
    ];
    $class = $map[$status] ?? 'bg-secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    return "<span class=\"badge {$class}\">{$label}</span>";
}
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clipboard2-check me-2"></i>Opening Balance Batches</span>
                    <?php if ($canWrite): ?>
                    <a href="<?= APP_URL ?>/index.php?page=opening-balance-create" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-circle me-1"></i> Create Opening Balance
                    </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (Session::has('success')): ?>
                        <div class="alert alert-success"><?= Session::flash('success') ?></div>
                    <?php endif; ?>
                    <?php if (Session::has('error')): ?>
                        <div class="alert alert-danger"><?= Session::flash('error') ?></div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Batch #</th>
                                    <th>Financial Year</th>
                                    <th>Period</th>
                                    <th>As Of</th>
                                    <th class="text-end">Total Debit</th>
                                    <th class="text-end">Total Credit</th>
                                    <th>Status</th>
                                    <th>Prepared By</th>
                                    <th>Approved By</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($batches)): ?>
                                    <tr><td colspan="10" class="text-center text-muted">No opening balance batches found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($batches as $b): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($b['batch_number']) ?></strong></td>
                                            <td><?= htmlspecialchars($b['financial_year_name'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($b['accounting_period_name'] ?? '') ?></td>
                                            <td><?= date('d M Y', strtotime($b['as_of_date'])) ?></td>
                                            <td class="text-end">Shs <?= number_format((float)$b['total_debit'], 2) ?></td>
                                            <td class="text-end">Shs <?= number_format((float)$b['total_credit'], 2) ?></td>
                                            <td><?= obStatusBadge($b['status']) ?></td>
                                            <td><?= htmlspecialchars($b['entered_by_name'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($b['approved_by_name'] ?? '-') ?></td>
                                            <td class="text-end">
                                                <a href="<?= APP_URL ?>/index.php?page=opening-balance-view&id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
