<?php $base = APP_URL . '/index.php'; ?>

<div class="mt-4 mb-3">
    <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Provisioning Movement</h1>
    <p class="text-muted mb-0" style="font-size:.78rem;">Movement between finalized runs, oldest to newest — reconciles to the 1185 allowance balance</p>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Run</th><th>As-Of Date</th><th class="text-end">Opening (Previous)</th>
                    <th class="text-end">Closing (Required)</th><th class="text-end">Increase</th><th class="text-end">Decrease</th>
                    <th class="text-end">Net Movement</th><th>Journal</th>
                </tr>
            </thead>
            <tbody>
                <?php $closingRunning = 0.0; foreach ($runs as $r): $delta = (float)$r['total_delta']; ?>
                <tr>
                    <td class="ps-3"><a href="<?= $base ?>?page=loan-provisioning-view&id=<?= $r['id'] ?>"><?= htmlspecialchars($r['run_number']) ?></a></td>
                    <td><?= htmlspecialchars($r['as_of_date']) ?></td>
                    <td class="text-end"><?= number_format((float)$r['total_previous_provision'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$r['total_required_provision'], 2) ?></td>
                    <td class="text-end text-success"><?= $delta > 0 ? number_format($delta, 2) : '—' ?></td>
                    <td class="text-end text-danger"><?= $delta < 0 ? number_format(abs($delta), 2) : '—' ?></td>
                    <td class="text-end fw-semibold"><?= number_format($delta, 2) ?></td>
                    <td><?= $r['journal_entry_id'] ? '#' . (int)$r['journal_entry_id'] : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($runs)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No finalized provisioning runs yet — no movement to show.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
