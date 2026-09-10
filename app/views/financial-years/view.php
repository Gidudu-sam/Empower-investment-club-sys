<?php
$pageTitle = $pageTitle ?? 'Financial Year';
$title = $pageTitle;
$icon  = 'bi-calendar-range';
$base  = APP_URL . '/index.php';
$yid   = (int)$year['id'];
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>
    <div class="text-muted small mb-3">Accounting &rsaquo; Setup &amp; Control &rsaquo; Financial Years</div>

    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-1"><?= htmlspecialchars($year['name']) ?>
                <?php $s = $year['status']; $cls = $s === 'active' ? 'success' : ($s === 'closed' ? 'secondary' : 'warning'); ?>
                <span class="badge bg-<?= $cls ?> ms-2"><?= ucfirst($s) ?></span>
            </h4>
            <div class="text-muted small"><?= date('d M Y', strtotime($year['start_date'])) ?> – <?= date('d M Y', strtotime($year['end_date'])) ?></div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= $base ?>?page=financial-years" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <?php if ($canWrite): ?>
                <?php if ($year['status'] !== 'closed'): ?>
                    <a href="<?= $base ?>?page=financial-year-edit&id=<?= $yid ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
                <?php endif; ?>
                <?php if ($year['status'] !== 'active' && $year['status'] !== 'closed'): ?>
                    <a href="<?= $base ?>?page=financial-year-activate-confirm&id=<?= $yid ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-check-circle me-1"></i>Activate</a>
                <?php endif; ?>
                <?php if ($year['status'] !== 'closed'): ?>
                    <a href="<?= $base ?>?page=financial-year-close-confirm&id=<?= $yid ?>" class="btn btn-warning btn-sm"><i class="bi bi-lock me-1"></i>Close Year</a>
                <?php else: ?>
                    <a href="<?= $base ?>?page=financial-year-reopen-confirm&id=<?= $yid ?>" class="btn btn-outline-danger btn-sm"><i class="bi bi-unlock me-1"></i>Reopen Year</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if (Session::has('error')): ?><div class="alert alert-danger"><?= htmlspecialchars(Session::flash('error')) ?></div><?php endif; ?>

    <?php if (Session::has('success')): ?><div class="alert alert-success"><?= htmlspecialchars(Session::flash('success')) ?></div><?php endif; ?>
    <?php if (Session::has('error')): ?><div class="alert alert-danger"><?= htmlspecialchars(Session::flash('error')) ?></div><?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-2"><div class="card text-center"><div class="card-body py-3">
            <div class="small text-muted">Periods</div><div class="fs-5 fw-bold"><?= count($periods) ?></div>
        </div></div></div>
        <div class="col-6 col-md-2"><div class="card text-center"><div class="card-body py-3">
            <div class="small text-muted">Journal Entries</div><div class="fs-5 fw-bold"><?= (int)$summary['journal_entries'] ?></div>
        </div></div></div>
        <div class="col-6 col-md-2"><div class="card text-center"><div class="card-body py-3">
            <div class="small text-muted">Total Debits</div><div class="fs-6 fw-bold">Shs <?= number_format($summary['total_debit'], 2) ?></div>
        </div></div></div>
        <div class="col-6 col-md-2"><div class="card text-center"><div class="card-body py-3">
            <div class="small text-muted">Income</div><div class="fs-6 fw-bold text-success">Shs <?= number_format($summary['total_income'], 2) ?></div>
        </div></div></div>
        <div class="col-6 col-md-2"><div class="card text-center"><div class="card-body py-3">
            <div class="small text-muted">Expenses</div><div class="fs-6 fw-bold text-danger">Shs <?= number_format($summary['total_expense'], 2) ?></div>
        </div></div></div>
        <div class="col-6 col-md-2"><div class="card text-center"><div class="card-body py-3">
            <div class="small text-muted">Net Result</div><div class="fs-6 fw-bold">Shs <?= number_format($summary['net_result'], 2) ?></div>
        </div></div></div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header">Accounting Periods</div>
                <div class="card-body p-0">
                    <?php if (empty($periods)): ?>
                        <p class="text-muted p-3 mb-0">No accounting periods created for this financial year yet.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Period</th><th>Dates</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($periods as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['name']) ?></td>
                                <td><?= date('d M', strtotime($p['start_date'])) ?> – <?= date('d M Y', strtotime($p['end_date'])) ?></td>
                                <td><?= $p['status'] === 'open' ? '<span class="badge bg-success">Open</span>' : '<span class="badge bg-secondary">Closed</span>' ?></td>
                                <td><a href="<?= $base ?>?page=accounting-period-view&id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header">Audit History</div>
                <div class="card-body">
                    <?php if (empty($audit)): ?>
                        <p class="text-muted mb-0">No year-management audit events recorded yet.</p>
                    <?php else: ?>
                        <?php foreach ($audit as $a): ?>
                            <div class="mb-2 pb-2 border-bottom small">
                                <div><?= date('d M Y H:i', strtotime($a['created_at'])) ?> — <strong><?= htmlspecialchars(str_replace('_', ' ', $a['action'])) ?></strong></div>
                                <div class="text-muted"><?= htmlspecialchars($a['description']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
