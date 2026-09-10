<?php
$pageTitle = $pageTitle ?? 'Expenses';
$title = $pageTitle;
$icon  = 'bi-receipt';
$canWrite = Session::hasRole(['admin', 'treasurer']);
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-receipt me-2"></i>Expenses</span>
            <div class="d-flex gap-2">
                <a href="<?= APP_URL ?>/index.php?page=expense-categories" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-tags me-1"></i> Categories
                </a>
                <?php if ($canWrite): ?>
                <a href="<?= APP_URL ?>/index.php?page=expense-create" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-circle me-1"></i> Record Expense
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
                            <th>Expense #</th>
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
                        <?php if (empty($expenses)): ?>
                            <tr><td colspan="9" class="text-center text-muted">No expenses recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($expenses as $e): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($e['expense_number']) ?></strong></td>
                                    <td><?= date('d M Y', strtotime($e['expense_date'])) ?></td>
                                    <td><?= htmlspecialchars($e['category_name']) ?></td>
                                    <td><?= htmlspecialchars($e['description'] ?? '') ?></td>
                                    <td class="text-end">Shs <?= number_format((float)$e['amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($e['payment_method']) ?></td>
                                    <td>
                                        <?php if ($e['status'] === 'posted'): ?>
                                            <span class="badge bg-success">Posted</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Draft</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($e['recorded_by_name']) ?></td>
                                    <td class="text-end">
                                        <a href="<?= APP_URL ?>/index.php?page=expense-view&id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-primary">
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
