<?php
$base = APP_URL . '/index.php';
$s = $settings;
$val = fn(string $k, string $d = '') => htmlspecialchars($s[$k]['value'] ?? $d);
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-bank2 me-2 text-warning"></i>Loan Settings
        </h1>
        <p class="text-muted mb-0 small">Configure loan interest rates and thresholds</p>
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
                <i class="bi bi-bank2 text-warning"></i>
                <h6 class="mb-0 fw-semibold">Loan Interest Configuration</h6>
            </div>
            <div class="card-body p-4">
                <div class="alert alert-info d-flex align-items-start gap-2 mb-4">
                    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
                    <div class="small">
                        These settings control how loan interest is calculated. Loans below the threshold
                        use the higher rate; loans at or above use the lower rate.
                    </div>
                </div>

                <form method="POST" action="<?= $base ?>?page=settings-loans-save">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Loan Amount Threshold (UGX)</label>
                        <div class="input-group">
                            <span class="input-group-text">Shs</span>
                            <input type="number" name="loan_threshold" class="form-control form-control-lg"
                                   value="<?= $val('loan_threshold', '1000000') ?>" min="0" step="1" required>
                        </div>
                        <div class="form-text">Loans below this amount use the higher interest rate.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Monthly Interest Rate — Below Threshold</label>
                        <div class="input-group">
                            <input type="number" name="loan_rate_below" class="form-control form-control-lg"
                                   value="<?= $val('loan_rate_below', '10') ?>" min="0" max="100" step="0.1" required>
                            <span class="input-group-text fw-bold">%</span>
                        </div>
                        <div class="form-text">Applied monthly to loans below the threshold (default 10%).</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Monthly Interest Rate — At or Above Threshold</label>
                        <div class="input-group">
                            <input type="number" name="loan_rate_above" class="form-control form-control-lg"
                                   value="<?= $val('loan_rate_above', '5') ?>" min="0" max="100" step="0.1" required>
                            <span class="input-group-text fw-bold">%</span>
                        </div>
                        <div class="form-text">Applied monthly to loans at or above the threshold (default 5%).</div>
                    </div>

                    <button type="submit" class="btn btn-primary px-5 fw-semibold">
                        <i class="bi bi-floppy me-2"></i>Save Loan Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
