<?php $base = APP_URL . '/index.php'; ?>
<?php extract([
    'icon'     => 'bi-gear-fill text-secondary',
    'title'    => 'System Settings',
    'subtitle' => 'Configure withdrawal policy and system preferences',
]); include VIEW_PATH . '/layouts/page-title.php'; ?>

<?php if(!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-box-arrow-up-right text-danger"></i>
                <h6 class="mb-0 fw-semibold">Withdrawal Policy</h6>
            </div>
            <div class="card-body p-4">
                <div class="alert alert-info d-flex align-items-start gap-2 mb-4">
                    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
                    <div class="small">
                        These settings control how annual withdrawals are calculated.
                        Changing them affects all <strong>future</strong> withdrawals only.
                    </div>
                </div>

                <form method="POST" action="<?=$base?>?page=settings-save">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="withdrawal_pct">
                            Withdrawal Percentage (%)
                        </label>
                        <div class="input-group">
                            <input type="number" id="withdrawal_pct" name="withdrawal_pct"
                                   class="form-control form-control-lg"
                                   value="<?= htmlspecialchars($settings['withdrawal_pct']['value'] ?? '50') ?>"
                                   min="1" max="100" step="0.01" required>
                            <span class="input-group-text fw-bold">%</span>
                        </div>
                        <div class="form-text"><?= htmlspecialchars($settings['withdrawal_pct']['label'] ?? '') ?></div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="retained_pct">
                            Retained Share Percentage (%)
                        </label>
                        <div class="input-group">
                            <input type="number" id="retained_pct" name="retained_pct"
                                   class="form-control form-control-lg"
                                   value="<?= htmlspecialchars($settings['retained_pct']['value'] ?? '50') ?>"
                                   min="1" max="100" step="0.01" required>
                            <span class="input-group-text fw-bold">%</span>
                        </div>
                        <div class="form-text"><?= htmlspecialchars($settings['retained_pct']['label'] ?? '') ?></div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="max_withdrawals_year">
                            Maximum Withdrawals Per Year
                        </label>
                        <input type="number" id="max_withdrawals_year" name="max_withdrawals_year"
                               class="form-control form-control-lg"
                               value="<?= htmlspecialchars($settings['max_withdrawals_year']['value'] ?? '1') ?>"
                               min="1" max="12" required>
                        <div class="form-text"><?= htmlspecialchars($settings['max_withdrawals_year']['label'] ?? '') ?></div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="share_value">
                            Share Value (Shs per share)
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">Shs</span>
                            <input type="number" id="share_value" name="share_value"
                                   class="form-control form-control-lg"
                                   value="<?= htmlspecialchars($settings['share_value']['value'] ?? '20000') ?>"
                                   min="1" step="1" required>
                        </div>
                        <div class="form-text">Used to calculate number of shares held on member statements.</div>
                    </div>

                    <div class="d-flex gap-3">
                        <button type="submit" class="btn btn-primary px-5 fw-semibold">
                            <i class="bi bi-floppy me-2"></i>Save Settings
                        </button>
                        <a href="<?=$base?>?page=dashboard" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
