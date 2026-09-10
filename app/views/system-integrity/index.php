<?php
$base = APP_URL . '/index.php';
$badgeClass = [
    'PASS' => 'bg-success-subtle text-success',
    'WARNING' => 'bg-warning-subtle text-warning',
    'ERROR' => 'bg-danger-subtle text-danger',
    'UNAVAILABLE' => 'bg-secondary-subtle text-secondary',
];
$icon = [
    'PASS' => 'bi-check-circle-fill',
    'WARNING' => 'bi-exclamation-triangle-fill',
    'ERROR' => 'bi-x-circle-fill',
    'UNAVAILABLE' => 'bi-dash-circle-fill',
];
$worstOverall = 'PASS';
$rank = ['PASS' => 0, 'UNAVAILABLE' => 1, 'WARNING' => 2, 'ERROR' => 3];
foreach ($results as $r) {
    if ($rank[$r['status']] > $rank[$worstOverall]) { $worstOverall = $r['status']; }
}
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-heart-pulse me-2 text-info"></i>System Integrity
        </h1>
        <p class="text-muted mb-0 small">Read-only technical and accounting-data health check. Detects issues; does not fix them.</p>
    </div>
    <a href="<?= $base ?>?page=system-integrity" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-clockwise me-1"></i>Re-run Checks
    </a>
</div>

<div class="alert <?= $badgeClass[$worstOverall] ?> d-flex align-items-center gap-2 mb-4">
    <i class="bi <?= $icon[$worstOverall] ?> flex-shrink-0 fs-5"></i>
    <div>
        <strong>Overall status: <?= $worstOverall ?></strong>
        <?php if ($worstOverall === 'ERROR'): ?>
            — one or more checks found a confirmed structural or accounting inconsistency. Review details below; this dashboard does not repair anything.
        <?php elseif ($worstOverall === 'WARNING'): ?>
            — one or more checks found something worth reviewing, not necessarily confirmed corruption.
        <?php else: ?>
            — no problems detected in any check.
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-list-check me-2"></i>Diagnostic Results</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Check</th>
                    <th>Status</th>
                    <th>Summary</th>
                    <th class="text-end">Issues</th>
                    <th class="text-end">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $r): ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($r['title']) ?></td>
                    <td>
                        <span class="badge <?= $badgeClass[$r['status']] ?>">
                            <i class="bi <?= $icon[$r['status']] ?> me-1"></i><?= $r['status'] ?>
                        </span>
                    </td>
                    <td class="text-muted small"><?= htmlspecialchars($r['message']) ?></td>
                    <td class="text-end"><?= (int)$r['count'] ?></td>
                    <td class="text-end">
                        <?php if (!empty($r['details'])): ?>
                        <a href="<?= $base ?>?page=system-integrity-detail&code=<?= urlencode($r['code']) ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-search"></i> View
                        </a>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="alert alert-secondary mt-3 small mb-0">
    <i class="bi bi-info-circle me-1"></i>
    This page only reads data. Issues found here require investigation/correction through a separate, controlled workflow (a later stage) — nothing on this page repairs, reverses, or posts anything.
</div>
