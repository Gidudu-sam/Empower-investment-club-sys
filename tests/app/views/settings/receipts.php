<?php
$base = APP_URL . '/index.php';
$s = $settings;
$val = fn(string $k, string $d = '') => htmlspecialchars($s[$k]['value'] ?? $d);
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-receipt me-2 text-success"></i>Receipt Settings
        </h1>
        <p class="text-muted mb-0 small">Configure receipt format and display preferences</p>
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

<?php include VIEW_PATH . '/settings/partials/nav-tabs.php'; ?>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-receipt text-success"></i>
                <h6 class="mb-0 fw-semibold">Receipt Configuration</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= $base ?>?page=settings-receipts-save">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Receipt Footer Text</label>
                        <textarea name="receipt_footer" class="form-control" rows="3"><?= $val('receipt_footer', 'Thank you for your contribution.') ?></textarea>
                        <div class="form-text">Displayed at the bottom of all printed receipts.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Authorized Signature Name</label>
                        <input type="text" name="receipt_authorized_name" class="form-control"
                               value="<?= $val('receipt_authorized_name') ?>" placeholder="e.g. John Doe - Treasurer">
                        <div class="form-text">Name printed under the signature line on receipts.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Receipt Number Prefix</label>
                        <input type="text" name="receipt_prefix" class="form-control"
                               value="<?= $val('receipt_prefix', 'RCT') ?>" maxlength="10">
                        <div class="form-text">Prefix used for receipt numbers (e.g. RCT, SAV, PAY).</div>
                    </div>

                    <button type="submit" class="btn btn-primary px-5 fw-semibold">
                        <i class="bi bi-floppy me-2"></i>Save Receipt Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
