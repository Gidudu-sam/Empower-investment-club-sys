<?php $base = APP_URL . '/index.php'; ?>

<div class="mt-4 mb-3">
    <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Calculate Provisioning Run</h1>
    <p class="text-muted mb-0" style="font-size:.78rem;">Policy <?= htmlspecialchars($policy['version']) ?> — principal-only exposure, due-date-gated aging</p>
</div>

<div class="row">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body">
                <?php if (empty($periods)): ?>
                    <div class="alert alert-warning mb-0">No open accounting period is available. A provisioning run can only be calculated for an open period.</div>
                <?php else: ?>
                <form method="POST" action="<?= $base ?>?page=loan-provisioning-calculate-store">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="mb-3">
                        <label class="form-label">Accounting Period</label>
                        <select name="accounting_period_id" class="form-select" required>
                            <option value="">Select a period</option>
                            <?php foreach ($periods as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (ends <?= htmlspecialchars($p['end_date']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">The as-of date is fixed at the selected period's own end date, per the approved policy — it cannot be changed here.</div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-calculator me-1"></i>Calculate</button>
                    <a href="<?= $base ?>?page=loan-provisioning" class="btn btn-outline-secondary">Cancel</a>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-semibold" style="font-size:.82rem;">Approved Bands (<?= htmlspecialchars($policy['version']) ?>)</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Bucket</th><th class="text-end">Rate</th></tr></thead>
                    <tbody>
                    <?php foreach ($policy['bands'] as $b): ?>
                        <tr><td><?= htmlspecialchars($b['bucket_name']) ?></td><td class="text-end"><?= number_format((float)$b['rate'], 2) ?>%</td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
