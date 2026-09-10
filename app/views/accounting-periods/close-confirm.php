<?php
$pageTitle = $pageTitle ?? 'Close Accounting Period';
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
                    <i class="bi bi-exclamation-triangle me-2"></i>Close <?= htmlspecialchars($period['name']) ?>?
                </div>
                <div class="card-body">
                    <?php if (Session::has('error')): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars(Session::flash('error')) ?></div>
                    <?php endif; ?>

                    <p>This will prevent ordinary financial postings to this accounting period
                    (<?= date('d M Y', strtotime($period['start_date'])) ?> – <?= date('d M Y', strtotime($period['end_date'])) ?>).
                    All existing activity, journals, and reports for this period will remain fully viewable.</p>

                    <div class="table-responsive">
                        <table class="table table-sm">
                        <tr><th>Journal Entries</th><td><?= (int)$summary['journal_entries'] ?></td></tr>
                        <tr><th>Total Debits</th><td>Shs <?= number_format($summary['total_debit'], 2) ?></td></tr>
                        <tr><th>Total Credits</th><td>Shs <?= number_format($summary['total_credit'], 2) ?></td></tr>
                        <tr><th>Balanced?</th><td><?= $bal ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No — closing will be blocked</span>' ?></td></tr>
                    </table>
                    </div>

                    <?php if (!$bal): ?>
                        <div class="alert alert-danger">This period's journal activity is not balanced. Closing
                        cannot proceed until this is investigated — nothing will be changed by attempting it.</div>
                    <?php else: ?>
                    <form method="POST" action="<?= $base ?>?page=accounting-period-close">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="period_id" value="<?= (int)$period['id'] ?>">
                        <div class="mb-3">
                            <label class="form-label">Reason for closing <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="2" required placeholder="e.g. Month-end close, all activity reconciled"></textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-warning"><i class="bi bi-lock me-1"></i>Confirm Close</button>
                            <a href="<?= $base ?>?page=accounting-period-view&id=<?= (int)$period['id'] ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
