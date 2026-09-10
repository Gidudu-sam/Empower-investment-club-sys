<?php $base = APP_URL . '/index.php'; ?>

<div class="mt-4 mb-3">
    <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Provisioning Summary</h1>
    <p class="text-muted mb-0" style="font-size:.78rem;">Read from finalized run snapshots only — never recalculated from live loan data</p>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Run</th><th>As-Of Date</th><th>Policy</th>
                    <th class="text-end">Total Exposure</th><th class="text-end">Previous Provision</th>
                    <th class="text-end">Required Provision</th><th class="text-end">Movement (Delta)</th><th>Journal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($runs as $r): ?>
                <tr>
                    <td class="ps-3"><a href="<?= $base ?>?page=loan-provisioning-view&id=<?= $r['id'] ?>"><?= htmlspecialchars($r['run_number']) ?></a></td>
                    <td><?= htmlspecialchars($r['as_of_date']) ?></td>
                    <td><?= htmlspecialchars($r['policy_version']) ?></td>
                    <td class="text-end"><?= number_format((float)$r['total_exposure'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$r['total_previous_provision'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$r['total_required_provision'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$r['total_delta'], 2) ?></td>
                    <td><?= $r['journal_entry_id'] ? '#' . (int)$r['journal_entry_id'] : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($runs)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No finalized provisioning runs yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
