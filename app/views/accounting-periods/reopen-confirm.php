<?php
$pageTitle = $pageTitle ?? 'Reopen Accounting Period';
$title = $pageTitle;
$icon  = 'bi-unlock';
$base  = APP_URL . '/index.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="row justify-content-center">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header bg-danger-subtle">
                    <i class="bi bi-exclamation-triangle me-2"></i>Reopen <?= htmlspecialchars($period['name']) ?>?
                </div>
                <div class="card-body">
                    <?php if (Session::has('error')): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars(Session::flash('error')) ?></div>
                    <?php endif; ?>

                    <p>Reopening allows ordinary financial postings to resume against this period
                    (<?= date('d M Y', strtotime($period['start_date'])) ?> – <?= date('d M Y', strtotime($period['end_date'])) ?>).
                    No existing journal entry will be altered by this action — reopening changes only this
                    period's own status.</p>

                    <?php if (!empty($period['close_reason'])): ?>
                        <div class="alert alert-secondary small">This period was closed with the reason:
                        "<?= htmlspecialchars($period['close_reason']) ?>"</div>
                    <?php endif; ?>

                    <form method="POST" action="<?= $base ?>?page=accounting-period-reopen">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="period_id" value="<?= (int)$period['id'] ?>">
                        <div class="mb-3">
                            <label class="form-label">Reason for reopening <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="2" required placeholder="e.g. Correction needed for a specific transaction"></textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-danger"><i class="bi bi-unlock me-1"></i>Confirm Reopen</button>
                            <a href="<?= $base ?>?page=accounting-period-view&id=<?= (int)$period['id'] ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
