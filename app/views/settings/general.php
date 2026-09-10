<?php
$base = APP_URL . '/index.php';
$s = $settings;
$val = fn(string $k, string $d = '') => htmlspecialchars($s[$k]['value'] ?? $d);
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-gear-fill me-2 text-secondary"></i>General Settings
        </h1>
        <p class="text-muted mb-0 small">Configure club identity and system preferences</p>
    </div>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Settings Navigation -->
<?php include VIEW_PATH . '/settings/partials/nav-tabs.php'; ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-building text-primary"></i>
                <h6 class="mb-0 fw-semibold">Club Information</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= $base ?>?page=settings-general-save" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Club Name</label>
                            <input type="text" name="club_name" class="form-control" value="<?= $val('club_name', 'Empower Investment Club') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Club Motto</label>
                            <input type="text" name="club_motto" class="form-control" value="<?= $val('club_motto') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Club Address</label>
                            <textarea name="club_address" class="form-control" rows="2"><?= $val('club_address') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Telephone</label>
                            <input type="text" name="club_phone" class="form-control" value="<?= $val('club_phone') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Address</label>
                            <input type="email" name="club_email" class="form-control" value="<?= $val('club_email') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">System Logo</label>
                            <input type="file" name="club_logo" class="form-control" accept="image/*">
                            <?php if ($val('club_logo')): ?>
                            <div class="form-text">Current: <?= $val('club_logo') ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex gap-3 mt-4">
                        <button type="submit" class="btn btn-primary px-4 fw-semibold">
                            <i class="bi bi-floppy me-2"></i>Save Organization Information
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- SA-1: split out of the form above -- pure system/display
             configuration, distinct from organization identity. Receipt
             Footer is deliberately NOT here; it lives only on the
             dedicated Receipt Settings page now (it used to be saved from
             both places at once). -->
        <div class="card mt-4">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-sliders text-secondary"></i>
                <h6 class="mb-0 fw-semibold">System Configuration</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= $base ?>?page=settings-system-config-save">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Currency</label>
                            <select name="currency" class="form-select">
                                <?php $curr = $val('currency', 'UGX'); ?>
                                <option value="UGX" <?= $curr === 'UGX' ? 'selected' : '' ?>>UGX (Uganda Shillings)</option>
                                <option value="KES" <?= $curr === 'KES' ? 'selected' : '' ?>>KES (Kenya Shillings)</option>
                                <option value="TZS" <?= $curr === 'TZS' ? 'selected' : '' ?>>TZS (Tanzania Shillings)</option>
                                <option value="USD" <?= $curr === 'USD' ? 'selected' : '' ?>>USD (US Dollars)</option>
                            </select>
                        </div>
                    </div>
                    <div class="d-flex gap-3 mt-4">
                        <button type="submit" class="btn btn-outline-primary px-4 fw-semibold">
                            <i class="bi bi-floppy me-2"></i>Save System Configuration
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
