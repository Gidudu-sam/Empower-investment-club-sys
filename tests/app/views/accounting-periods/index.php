<?php
$pageTitle = $pageTitle ?? 'Accounting Periods';
$title = $pageTitle;
$icon  = 'bi-calendar3';
$base = APP_URL . '/index.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar3 me-2"></i>Accounting Periods</span>
                    <div class="d-flex gap-2">
                        <a href="<?= $base ?>?page=financial-years" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-calendar-range me-1"></i> Financial Years
                        </a>
                        <?php if ($canWrite): ?>
                        <a href="<?= $base ?>?page=accounting-period-create" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-circle me-1"></i> Create Period
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (Session::has('success')): ?>
                        <div class="alert alert-success"><?= htmlspecialchars(Session::flash('success')) ?></div>
                    <?php endif; ?>
                    <?php if (Session::has('error')): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars(Session::flash('error')) ?></div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Financial Year</th>
                                    <th>Period Name</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Status</th>
                                    <th class="text-end">Journal Entries</th>
                                    <th class="text-end">Total Debits</th>
                                    <th class="text-end">Total Credits</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($periods)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted">No accounting periods found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($periods as $period): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($period['financial_year_name'] ?? 'N/A') ?></strong>
                                                <br>
                                                <small class="text-muted"><?= htmlspecialchars($period['financial_year_status'] ?? '') ?></small>
                                            </td>
                                            <td>
                                                <a href="<?= $base ?>?page=accounting-period-view&id=<?= (int)$period['id'] ?>">
                                                    <?= htmlspecialchars($period['name']) ?>
                                                </a>
                                            </td>
                                            <td><?= date('d M Y', strtotime($period['start_date'])) ?></td>
                                            <td><?= date('d M Y', strtotime($period['end_date'])) ?></td>
                                            <td>
                                                <?php if ($period['status'] === 'open'): ?>
                                                    <span class="badge bg-success"><i class="bi bi-unlock"></i> Open</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><i class="bi bi-lock"></i> Closed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end"><?= (int)$period['journal_entries'] ?></td>
                                            <td class="text-end">Shs <?= number_format((float)$period['total_debit'], 2) ?></td>
                                            <td class="text-end">Shs <?= number_format((float)$period['total_credit'], 2) ?></td>
                                            <td class="text-end">
                                                <a href="<?= $base ?>?page=accounting-period-view&id=<?= (int)$period['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                                <?php if ($canWrite): ?>
                                                    <?php if ($period['status'] === 'open'): ?>
                                                        <a href="<?= $base ?>?page=accounting-period-close-confirm&id=<?= (int)$period['id'] ?>" class="btn btn-sm btn-warning">
                                                            <i class="bi bi-lock"></i> Close
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="<?= $base ?>?page=accounting-period-reopen-confirm&id=<?= (int)$period['id'] ?>" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-unlock"></i> Reopen
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
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
