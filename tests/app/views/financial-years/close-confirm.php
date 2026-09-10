<?php
$pageTitle = $pageTitle ?? 'Close Financial Year';
$title = $pageTitle;
$icon  = 'bi-lock';
$base  = APP_URL . '/index.php';
$bal = abs($summary['total_debit'] - $summary['total_credit']) < 0.01;
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="row justify-content-center">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header bg-warning-subtle">
                    <i class="bi bi-exclamation-triangle me-2"></i>Close <?= htmlspecialchars($year['name']) ?>?
                </div>
                <div class="card-body">
                    <?php if (Session::has('error')): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars(Session::flash('error')) ?></div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-sm">
                        <tr><th>Open Periods Remaining</th><td><?= $openPeriodCount ?></td></tr>
                        <tr><th>Journal Entries</th><td><?= (int)$summary['journal_entries'] ?></td></tr>
                        <tr><th>Total Debits</th><td>Shs <?= number_format($summary['total_debit'], 2) ?></td></tr>
                        <tr><th>Total Credits</th><td>Shs <?= number_format($summary['total_credit'], 2) ?></td></tr>
                        <tr><th>Balanced?</th><td><?= $bal ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>' ?></td></tr>
                    </table>
                    </div>

                    <?php if ($openPeriodCount > 0): ?>
                        <div class="alert alert-danger">All accounting periods within this financial year must be
                        closed first (<?= $openPeriodCount ?> still open). Closing is blocked.</div>
                    <?php elseif (!$bal): ?>
                        <div class="alert alert-danger">This year's journal activity is not balanced. Closing is blocked.</div>
                    <?php else: ?>
                    <form method="POST" action="<?= $base ?>?page=financial-year-close">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="year_id" value="<?= (int)$year['id'] ?>">
                        <div class="mb-3">
                            <label class="form-label">Reason for closing <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="2" required></textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-warning"><i class="bi bi-lock me-1"></i>Confirm Close</button>
                            <a href="<?= $base ?>?page=financial-year-view&id=<?= (int)$year['id'] ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
