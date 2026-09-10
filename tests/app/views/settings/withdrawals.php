<?php
$base = APP_URL . '/index.php';
$s = $settings;
$val = fn(string $k, string $d = '') => htmlspecialchars($s[$k]['value'] ?? $d);
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-box-arrow-up-right me-2 text-danger"></i>Withdrawal & Share Settings
        </h1>
        <p class="text-muted mb-0 small">Configure withdrawal policy and share valuation</p>
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
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-box-arrow-up-right text-danger"></i>
                <h6 class="mb-0 fw-semibold">Withdrawal Policy</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= $base ?>?page=settings-withdrawals-save">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Withdrawal Percentage (%)</label>
                        <div class="input-group">
                            <input type="number" name="withdrawal_pct" class="form-control form-control-lg"
                                   value="<?= $val('withdrawal_pct', '50') ?>" min="1" max="100" step="0.01" required>
                            <span class="input-group-text fw-bold">%</span>
                        </div>
                        <div class="form-text">Maximum percentage of savings a member can withdraw.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Retained Share Percentage (%)</label>
                        <div class="input-group">
                            <input type="number" name="retained_pct" class="form-control form-control-lg"
                                   value="<?= $val('retained_pct', '50') ?>" min="1" max="100" step="0.01" required>
                            <span class="input-group-text fw-bold">%</span>
                        </div>
                        <div class="form-text">Percentage retained as share capital.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Maximum Withdrawals Per Financial Year</label>
                        <input type="number" name="max_withdrawals_year" class="form-control form-control-lg"
                               value="<?= $val('max_withdrawals_year', '1') ?>" min="1" max="12" required>
                    </div>

                    <hr class="my-4">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-pie-chart me-2 text-info"></i>Share Settings</h6>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Share Price (Shs per share)</label>
                        <div class="input-group">
                            <span class="input-group-text">Shs</span>
                            <input type="number" name="share_value" class="form-control form-control-lg"
                                   value="<?= $val('share_value', '20000') ?>" min="1" step="1" required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Minimum Required Shares</label>
                        <input type="number" name="min_required_shares" class="form-control form-control-lg"
                               value="<?= $val('min_required_shares', '0') ?>" min="0" step="1">
                        <div class="form-text">Set to 0 for no minimum requirement.</div>
                    </div>

                    <button type="submit" class="btn btn-primary px-5 fw-semibold">
                        <i class="bi bi-floppy me-2"></i>Save Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
