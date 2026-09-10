<?php
$base = APP_URL . '/index.php';
$labels = ['compulsory' => 'Compulsory', 'voluntary' => 'Voluntary', 'joint' => 'Joint', 'corporate' => 'Corporate'];
$v = fn(string $k, string $d = '') => htmlspecialchars($old[$k] ?? $d);
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-plus-circle me-2 text-danger"></i>New <?= htmlspecialchars($labels[$accountType] ?? ucfirst($accountType)) ?> Withdrawal Policy
        </h1>
        <p class="text-muted mb-0 small">Creates a new effective-dated version. Historical withdrawals continue to use the policy that was in effect at their own date.</p>
    </div>
    <a href="<?= $base ?>?page=withdrawal-policies" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($currentPolicy): ?>
<div class="alert alert-warning d-flex align-items-start gap-2">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
    <div>
        There is already an active policy for <?= htmlspecialchars($labels[$accountType] ?? $accountType) ?>
        (max <?= number_format((float)$currentPolicy['maximum_withdrawal_percent'], 2) ?>%, share
        <?= number_format((float)$currentPolicy['share_conversion_percent'], 2) ?>%, effective
        <?= date('d M Y', strtotime($currentPolicy['effective_from'])) ?>). Submitting this form will
        automatically close it out (effective_to = the day before this new policy's effective_from)
        and supersede it — its history is preserved, not deleted.
    </div>
</div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body p-4">
                <form method="POST" action="<?= $base ?>?page=withdrawal-policy-store">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="account_type" value="<?= htmlspecialchars($accountType) ?>">

                    <div class="mb-4 form-check form-switch">
                        <input type="checkbox" class="form-check-input" role="switch" id="withdrawal_enabled" name="withdrawal_enabled" value="1"
                               <?= (!isset($old['withdrawal_enabled']) || $old['withdrawal_enabled']) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="withdrawal_enabled">Withdrawal Enabled</label>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Maximum Withdrawal Percent (%)</label>
                        <div class="input-group">
                            <input type="number" name="maximum_withdrawal_percent" class="form-control form-control-lg"
                                   value="<?= $v('maximum_withdrawal_percent', '50') ?>" min="0" max="100" step="0.01" required>
                            <span class="input-group-text fw-bold">%</span>
                        </div>
                        <div class="form-text">Maximum share of the qualifying balance that may be paid out as cash.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Share Conversion Percent (%)</label>
                        <div class="input-group">
                            <input type="number" name="share_conversion_percent" class="form-control form-control-lg"
                                   value="<?= $v('share_conversion_percent', '50') ?>" min="0" max="100" step="0.01" required>
                            <span class="input-group-text fw-bold">%</span>
                        </div>
                        <div class="form-text">Independent of the above — not assumed to be "100 − max". The two combined cannot exceed 100%.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Frequency</label>
                        <select name="frequency" class="form-select form-select-lg">
                            <?php foreach ($frequencies as $f): ?>
                            <option value="<?= $f ?>" <?= ($old['frequency'] ?? '') === $f ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $f)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Minimum Balance After Withdrawal (optional)</label>
                        <div class="input-group">
                            <span class="input-group-text">Shs</span>
                            <input type="number" name="minimum_balance" class="form-control form-control-lg"
                                   value="<?= $v('minimum_balance', '') ?>" min="0" step="1" placeholder="No minimum">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Effective From <span class="text-danger">*</span></label>
                            <input type="date" name="effective_from" class="form-control form-control-lg"
                                   value="<?= $v('effective_from', date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Effective To (optional)</label>
                            <input type="date" name="effective_to" class="form-control form-control-lg" value="<?= $v('effective_to', '') ?>">
                            <div class="form-text">Leave blank for "in effect indefinitely".</div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary px-5 fw-semibold">
                        <i class="bi bi-floppy me-2"></i>Create Policy Version
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
