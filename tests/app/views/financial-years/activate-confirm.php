<?php
$pageTitle = $pageTitle ?? 'Activate Financial Year';
$title = $pageTitle;
$icon  = 'bi-check-circle';
$base  = APP_URL . '/index.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>
    <div class="text-muted small mb-3">Accounting &rsaquo; Setup &amp; Control &rsaquo; Financial Years</div>

    <div class="row justify-content-center">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header bg-success-subtle">
                    <i class="bi bi-check-circle me-2"></i>Activate <?= htmlspecialchars($year['name']) ?>?
                </div>
                <div class="card-body">
                    <?php if (Session::has('error')): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars(Session::flash('error')) ?></div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-sm">
                        <tr><th>Dates</th><td><?= date('d M Y', strtotime($year['start_date'])) ?> – <?= date('d M Y', strtotime($year['end_date'])) ?></td></tr>
                        <tr><th>Current Status</th><td><?= ucfirst($year['status']) ?></td></tr>
                    </table>
                    </div>

                    <?php if ($currentActive): ?>
                        <div class="alert alert-warning">
                            <strong><?= htmlspecialchars($currentActive['name']) ?></strong> is currently the active financial year.
                            Activating <strong><?= htmlspecialchars($year['name']) ?></strong> will move it to <strong>Pending</strong> —
                            new postings will resolve against <?= htmlspecialchars($year['name']) ?> instead.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">No other financial year is currently active.</div>
                    <?php endif; ?>

                    <form method="POST" action="<?= $base ?>?page=financial-year-activate">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="year_id" value="<?= (int)$year['id'] ?>">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Confirm Activate</button>
                            <a href="<?= $base ?>?page=financial-year-view&id=<?= (int)$year['id'] ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
