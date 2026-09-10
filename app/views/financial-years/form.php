<?php
$pageTitle = $pageTitle ?? 'Financial Year';
$title = $pageTitle;
$icon  = 'bi-calendar-range';
$base  = APP_URL . '/index.php';
$isEdit = $formMode === 'edit';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>
    <div class="text-muted small mb-3">Accounting &rsaquo; Setup &amp; Control &rsaquo; Financial Years</div>

    <div class="row justify-content-center">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi <?= $icon ?> me-2"></i><?= $isEdit ? 'Edit' : 'Create' ?> Financial Year
                </div>
                <div class="card-body">
                    <?php if (Session::has('error')): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars(Session::flash('error')) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="<?= $base ?>?page=<?= $isEdit ? 'financial-year-update' : 'financial-year-store' ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <?php if ($isEdit): ?>
                            <input type="hidden" name="id" value="<?= (int)$year['id'] ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. FY 2027"
                                   value="<?= htmlspecialchars($year['name'] ?? '') ?>" required>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label fw-semibold">Start Date</label>
                                <input type="date" name="start_date" class="form-control"
                                       value="<?= htmlspecialchars($year['start_date'] ?? '') ?>" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label fw-semibold">End Date</label>
                                <input type="date" name="end_date" class="form-control"
                                       value="<?= htmlspecialchars($year['end_date'] ?? '') ?>" required>
                            </div>
                        </div>

                        <?php if ($isEdit): ?>
                            <p class="text-muted small">Status: <strong><?= ucfirst($year['status']) ?></strong> — editing is only available for pending or active years. A closed year must be reopened first.</p>
                        <?php else: ?>
                            <p class="text-muted small">New financial years start as <strong>Pending</strong>. Use Activate on the detail page when it's ready to accept postings.</p>
                        <?php endif; ?>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-floppy me-1"></i><?= $isEdit ? 'Save Changes' : 'Create Financial Year' ?>
                            </button>
                            <a href="<?= $base ?>?page=<?= $isEdit ? 'financial-year-view&id=' . (int)$year['id'] : 'financial-years' ?>"
                               class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
