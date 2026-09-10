<?php
$pageTitle = $pageTitle ?? 'Other Income';
$title = $pageTitle;
$icon  = 'bi-cash-stack';
$canWrite = Session::hasRole(['admin', 'treasurer']);
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-cash-stack me-2"></i>Other Income</span>
            <div class="d-flex gap-2">
                <a href="<?= APP_URL ?>/index.php?page=other-income-categories" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-tags me-1"></i> Categories
                </a>
                <?php if ($canWrite): ?>
                <a href="<?= APP_URL ?>/index.php?page=other-income-create" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-circle me-1"></i> Record Income
                </a>
                <?php endif; ?>
            </div>
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
                            <th>Income #</th>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th class="text-end">Amount</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th>Recorded By</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($incomes)): ?>
                            <tr><td colspan="9" class="text-center text-muted">No Other Income recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($incomes as $i): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($i['income_number']) ?></strong></td>
                                    <td><?= date('d M Y', strtotime($i['income_date'])) ?></td>
                                    <td><?= htmlspecialchars($i['category_name']) ?></td>
                                    <td><?= htmlspecialchars($i['description'] ?? '') ?></td>
                                    <td class="text-end">Shs <?= number_format((float)$i['amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($i['payment_method']) ?></td>
                                    <td>
                                        <?php if ($i['status'] === 'posted'): ?>
                                            <span class="badge bg-success">Posted</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Draft</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($i['recorded_by_name']) ?></td>
                                    <td class="text-end">
                                        <a href="<?= APP_URL ?>/index.php?page=other-income-view&id=<?= $i['id'] ?>" class="btn btn-sm btn-outline-primary">
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
