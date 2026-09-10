<?php
$base = APP_URL . '/index.php';
$v = fn(string $k, string $d = '') => htmlspecialchars((string)($policy[$k] ?? $d));
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800"><i class="bi bi-pencil me-2 text-danger"></i>Edit Draft Policy</h1>
        <p class="text-muted mb-0 small">Only editable because this version's effective date has not yet arrived.</p>
    </div>
    <a href="<?= $base ?>?page=withdrawal-policies" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body p-4">
                <form method="POST" action="<?= $base ?>?page=withdrawal-policy-update-draft">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= (int)$policy['id'] ?>">
                    <input type="hidden" name="account_type" value="<?= htmlspecialchars($policy['account_type']) ?>">

                    <div class="mb-4 form-check form-switch">
                        <input type="checkbox" class="form-check-input" role="switch" id="withdrawal_enabled" name="withdrawal_enabled" value="1"
                               <?= $policy['withdrawal_enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="withdrawal_enabled">Withdrawal Enabled</label>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Maximum Withdrawal Percent (%)</label>
                        <input type="number" name="maximum_withdrawal_percent" class="form-control form-control-lg"
                               value="<?= $v('maximum_withdrawal_percent') ?>" min="0" max="100" step="0.01" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Share Conversion Percent (%)</label>
                        <input type="number" name="share_conversion_percent" class="form-control form-control-lg"
                               value="<?= $v('share_conversion_percent') ?>" min="0" max="100" step="0.01" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Frequency</label>
                        <select name="frequency" class="form-select form-select-lg">
                            <?php foreach (WithdrawalPolicyModel::FREQUENCIES as $f): ?>
                            <option value="<?= $f ?>" <?= $policy['frequency'] === $f ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $f)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Minimum Balance After Withdrawal (optional)</label>
                        <input type="number" name="minimum_balance" class="form-control form-control-lg"
                               value="<?= $v('minimum_balance') ?>" min="0" step="1" placeholder="No minimum">
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Effective From <span class="text-danger">*</span></label>
                            <input type="date" name="effective_from" class="form-control form-control-lg" value="<?= $v('effective_from') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Effective To (optional)</label>
                            <input type="date" name="effective_to" class="form-control form-control-lg" value="<?= $v('effective_to') ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary px-5 fw-semibold"><i class="bi bi-floppy me-2"></i>Save Draft</button>
                </form>
            </div>
        </div>
    </div>
</div>
