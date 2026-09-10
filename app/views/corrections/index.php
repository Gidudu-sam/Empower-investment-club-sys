<?php
$base = APP_URL . '/index.php';
$statusBadge = [
    'prepared' => 'bg-secondary-subtle text-secondary', 'confirmed' => 'bg-info-subtle text-info',
    'executed' => 'bg-success-subtle text-success', 'failed' => 'bg-danger-subtle text-danger',
    'cancelled' => 'bg-secondary-subtle text-secondary',
];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800"><i class="bi bi-shield-exclamation me-2 text-danger"></i>Controlled Corrections</h1>
        <p class="text-muted mb-0 small">Controlled, auditable financial remediation. Not a generic editor.</p>
    </div>
    <a href="<?= $base ?>?page=investigation" class="btn btn-outline-secondary btn-sm"><i class="bi bi-search me-1"></i>Start from Investigation</a>
</div>

<div class="alert alert-warning d-flex align-items-start gap-2 mb-4">
    <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
    <div><strong>Financial correction changes system records and accounting history.</strong> Verify all evidence before proceeding. Every correction here begins from an investigated journal entry, requires a documented reason, and must be explicitly confirmed before anything is written.</div>
</div>

<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0 fw-semibold">Correction Types</h6></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Type</th><th>Status</th><th>Notes</th></tr></thead>
            <tbody>
                <?php foreach ($types as $code => $t): ?>
                <tr>
                    <td class="fw-semibold small"><?= htmlspecialchars($t['label']) ?></td>
                    <td>
                        <?php if ($t['available']): ?>
                        <span class="badge bg-success-subtle text-success">Available</span>
                        <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary">Deferred</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars($t['reason']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold">Correction History</h6>
        <span class="badge bg-secondary-subtle text-secondary"><?= count($recent) ?> shown</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Correction</th><th>Type</th><th>Target</th><th>Status</th><th>Prepared By</th><th>Prepared At</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $c): ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($c['correction_number']) ?></td>
                    <td class="small"><?= htmlspecialchars($c['correction_type']) ?></td>
                    <td class="small"><?= htmlspecialchars($c['target_entity_type']) ?> <?= htmlspecialchars((string)$c['target_reference']) ?></td>
                    <td><span class="badge <?= $statusBadge[$c['status']] ?? '' ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                    <td class="small"><?= htmlspecialchars($c['prepared_by_name'] ?? '—') ?></td>
                    <td class="small text-muted"><?= htmlspecialchars((string)$c['prepared_at']) ?></td>
                    <td class="text-end">
                        <?php if ($c['status'] === 'executed'): ?>
                        <a href="<?= $base ?>?page=correction-result&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary">View Result</a>
                        <?php elseif (in_array($c['status'], ['prepared', 'confirmed'], true)): ?>
                        <a href="<?= $base ?>?page=correction-review&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-warning">Review</a>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recent)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No corrections have been prepared yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
