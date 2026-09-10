<?php $base = APP_URL . '/index.php'; ?>

<div class="mt-4 mb-3">
    <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Provisioning by Aging Bucket</h1>
    <p class="text-muted mb-0" style="font-size:.78rem;">Grouped from a finalized run's frozen detail rows only</p>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="loan-provisioning-report-by-bucket">
            <div class="col-md-4">
                <label class="form-label small">Finalized Run</label>
                <select name="run_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">Select a run</option>
                    <?php foreach ($finalizedRuns as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= $runId === (int)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['run_number']) ?> (<?= htmlspecialchars($r['as_of_date']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($runId): ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead><tr><th class="ps-3">Bucket</th><th class="text-end">Rate</th><th class="text-end">Loans</th><th class="text-end">Exposure</th><th class="text-end">Required Provision</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="ps-3"><?= htmlspecialchars($r['bucket_name']) ?></td>
                    <td class="text-end"><?= number_format((float)$r['applied_rate'], 2) ?>%</td>
                    <td class="text-end"><?= (int)$r['loan_count'] ?></td>
                    <td class="text-end"><?= number_format((float)$r['total_exposure'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$r['total_required'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No eligible loans in this run.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
