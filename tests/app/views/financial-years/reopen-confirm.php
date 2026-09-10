<?php
$pageTitle = $pageTitle ?? 'Reopen Financial Year';
$title = $pageTitle;
$icon  = 'bi-unlock';
$base  = APP_URL . '/index.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="row justify-content-center">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header bg-danger-subtle">
                    <i class="bi bi-exclamation-triangle me-2"></i>Reopen <?= htmlspecialchars($year['name']) ?>?
                </div>
                <div class="card-body">
                    <?php if (Session::has('error')): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars(Session::flash('error')) ?></div>
                    <?php endif; ?>

                    <p>Reopening returns this financial year to active status. No accounting period or journal
                    entry is altered by this action.</p>

                    <?php if (!empty($year['close_reason'])): ?>
                        <div class="alert alert-secondary small">This year was closed with the reason:
                        "<?= htmlspecialchars($year['close_reason']) ?>"</div>
                    <?php endif; ?>

                    <form method="POST" action="<?= $base ?>?page=financial-year-reopen">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="year_id" value="<?= (int)$year['id'] ?>">
                        <div class="mb-3">
                            <label class="form-label">Reason for reopening <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="2" required></textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-danger"><i class="bi bi-unlock me-1"></i>Confirm Reopen</button>
                            <a href="<?= $base ?>?page=financial-year-view&id=<?= (int)$year['id'] ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
