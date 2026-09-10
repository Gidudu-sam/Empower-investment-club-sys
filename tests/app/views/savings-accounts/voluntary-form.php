<?php
$pageTitle = $pageTitle ?? 'Open Voluntary Savings Account';
$title     = $pageTitle;
$icon      = 'bi-bank';
$base      = APP_URL . '/index.php';
require_once VIEW_PATH . '/savings-accounts/partials/member-search-field.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-person-circle me-2 text-primary"></i>Open Voluntary Savings Account
                </div>
                <div class="card-body">
                    <div class="alert alert-info small d-flex gap-2 mb-4">
                        <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
                        <span>
                            Voluntary Savings is a flexible account and does not participate
                            in compulsory savings qualification.
                        </span>
                    </div>

                    <form method="POST" action="<?= $base ?>?page=savings-account-voluntary-store">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                Member <span class="text-danger">*</span>
                            </label>
                            <?= memberSearchField('member_id', 'vol', 'Search by name or member number…') ?>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Opening Date</label>
                            <input type="date" name="opened_date" class="form-control"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-bank me-1"></i>Open Voluntary Account
                            </button>
                            <a href="<?= $base ?>?page=savings-account-open" class="btn btn-outline-secondary">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php require VIEW_PATH . '/savings-accounts/partials/member-search-js.php'; ?>
