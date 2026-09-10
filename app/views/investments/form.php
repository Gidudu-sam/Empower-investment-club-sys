<?php
$pageTitle = $pageTitle ?? 'Record Investment';
$title = $pageTitle;
$icon  = 'bi-graph-up';
$base = APP_URL . '/index.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if (Session::has('error')): ?>
        <div class="alert alert-danger"><?= Session::flash('error') ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header"><i class="bi bi-graph-up me-2"></i>Record Investment</div>
                <div class="card-body">
                    <div class="alert alert-info small">
                        This creates the investment as a <strong>draft</strong>. It must be submitted for approval and approved by another user before it can be posted to the ledger.
                    </div>

                    <form method="POST" action="<?= $base ?>?page=investment-store">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Investment Type</label>
                                <select name="investment_type_id" id="investmentType" class="form-select" required onchange="updateDefaultAccount()">
                                    <option value="">Select Type</option>
                                    <?php foreach ($types as $t): ?>
                                        <option value="<?= $t['id'] ?>" data-asset-account="<?= $t['asset_gl_account_id'] ?>">
                                            <?= htmlspecialchars($t['type_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Provider / Institution</label>
                                <input type="text" name="provider_name" class="form-control">
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Reference / Certificate Number</label>
                                <input type="text" name="reference" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Principal Amount (Shs)</label>
                                <input type="number" step="0.01" min="0.01" name="principal_amount" class="form-control" required>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Maturity Date</label>
                                <input type="date" name="maturity_date" class="form-control">
                                <div class="form-text">Leave blank for open-ended investments (e.g. shares).</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Expected Return / Rate (%)</label>
                                <input type="number" step="0.000001" name="expected_rate" class="form-control">
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Funding Account (Cash/Bank)</label>
                                <select name="funding_account_id" class="form-select" required>
                                    <option value="">Select Account</option>
                                    <?php foreach ($accounts as $a): ?>
                                        <?php if ($a['type'] === 'asset'): ?>
                                        <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Investment (Asset) Account</label>
                                <select name="investment_account_id" id="investmentAccount" class="form-select">
                                    <option value="">Default from investment type</option>
                                    <?php foreach ($accounts as $a): ?>
                                        <?php if ($a['type'] === 'asset'): ?>
                                        <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Only override if this specific investment needs a different asset account.</div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3"></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i> Create Draft
                            </button>
                            <a href="<?= $base ?>?page=investments" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

<script>
function updateDefaultAccount() {
    const sel = document.getElementById('investmentType');
    const opt = sel.options[sel.selectedIndex];
    const assetAccountId = opt ? opt.getAttribute('data-asset-account') : '';
    const accSel = document.getElementById('investmentAccount');
    if (assetAccountId) {
        for (const o of accSel.options) {
            if (o.value === assetAccountId) {
                o.textContent = o.textContent.replace(' (type default)', '') + ' (type default)';
            }
        }
    }
}
</script>
