<?php $base = APP_URL . '/index.php'; ?>

<div class="mt-4 mb-3">
    <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Loan-Level Provisioning</h1>
    <p class="text-muted mb-0" style="font-size:.78rem;">Frozen loan-by-loan detail from a finalized run — never recalculated</p>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="loan-provisioning-report-loan-level">
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
            <thead>
                <tr>
                    <th class="ps-3">Loan</th><th>Member</th><th class="text-end">Principal</th><th class="text-end">Exposure</th>
                    <th>Oldest Due</th><th class="text-end">Days</th><th>Bucket</th><th class="text-end">Rate</th>
                    <th class="text-end">Required</th><th class="text-end">Previous</th><th class="text-end">Delta</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($details as $d): ?>
                <tr>
                    <td class="ps-3">#<?= (int)$d['loan_id'] ?></td>
                    <td>#<?= (int)$d['member_id'] ?></td>
                    <td class="text-end"><?= number_format((float)$d['original_principal'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$d['unpaid_principal_exposure'], 2) ?></td>
                    <td><?= htmlspecialchars($d['oldest_qualifying_due_date'] ?? '—') ?></td>
                    <td class="text-end"><?= (int)$d['days_past_due'] ?></td>
                    <td><?= htmlspecialchars($d['bucket_name']) ?></td>
                    <td class="text-end"><?= number_format((float)$d['applied_rate'], 2) ?>%</td>
                    <td class="text-end"><?= number_format((float)$d['required_provision'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$d['previous_provision'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$d['delta'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($details)): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">No eligible loans in this run.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
