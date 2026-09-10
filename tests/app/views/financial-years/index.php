<?php
$pageTitle = $pageTitle ?? 'Financial Years';
$title = $pageTitle;
$icon  = 'bi-calendar-range';
$base  = APP_URL . '/index.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>
    <div class="text-muted small mb-3">Accounting &rsaquo; Setup &amp; Control &rsaquo; Financial Years</div>

    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar-range me-2"></i>Financial Years</span>
                    <div class="d-flex gap-2">
                        <a href="<?= $base ?>?page=accounting-periods" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-calendar3 me-1"></i> Accounting Periods
                        </a>
                        <?php if ($canWrite): ?>
                        <a href="<?= $base ?>?page=financial-year-create" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-circle me-1"></i> Create Financial Year
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
                                    <th>Name</th><th>Start</th><th>End</th><th>Status</th>
                                    <th class="text-end">Journal Entries</th>
                                    <th class="text-end">Total Debits</th>
                                    <th class="text-end">Total Credits</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($years)): ?>
                                    <tr><td colspan="8" class="text-center text-muted">No financial years found.</td></tr>
                                <?php else: foreach ($years as $y): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= $base ?>?page=financial-year-view&id=<?= (int)$y['id'] ?>"><?= htmlspecialchars($y['name']) ?></a>
                                            <?php if ($y['is_legacy']): ?><span class="badge bg-secondary ms-1">Legacy</span><?php endif; ?>
                                        </td>
                                        <td><?= date('d M Y', strtotime($y['start_date'])) ?></td>
                                        <td><?= date('d M Y', strtotime($y['end_date'])) ?></td>
                                        <td>
                                            <?php $s = $y['status']; $cls = $s === 'active' ? 'success' : ($s === 'closed' ? 'secondary' : 'warning'); ?>
                                            <span class="badge bg-<?= $cls ?>"><?= ucfirst($s) ?></span>
                                        </td>
                                        <td class="text-end"><?= (int)$y['journal_entries'] ?></td>
                                        <td class="text-end">Shs <?= number_format((float)$y['total_debit'], 2) ?></td>
                                        <td class="text-end">Shs <?= number_format((float)$y['total_credit'], 2) ?></td>
                                        <td class="text-end">
                                            <a href="<?= $base ?>?page=financial-year-view&id=<?= (int)$y['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> View</a>
                                            <?php if ($canWrite && $y['status'] !== 'closed'): ?>
                                            <a href="<?= $base ?>?page=financial-year-edit&id=<?= (int)$y['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
