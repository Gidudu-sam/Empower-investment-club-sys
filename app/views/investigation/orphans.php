<?php
$base = APP_URL . '/index.php';
$totalPages = max(1, (int)ceil($total / $limit));
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h4 mb-1 fw-bold text-gray-800"><i class="bi bi-diagram-3 me-2 text-info"></i>Orphan Journal References</h1>
        <p class="text-muted mb-0 small">Journal entries whose source_module/source_reference_id points at a record that no longer exists. Read-only.</p>
    </div>
    <a href="<?= $base ?>?page=investigation" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Search</a>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold">Results</h6>
        <span class="badge bg-secondary-subtle text-secondary"><?= $total ?> total</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Journal</th><th>Date</th><th>Source Module</th><th>Missing Source ID</th><th>Classification</th><th>State</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($r['entry_number']) ?></td>
                    <td class="small"><?= htmlspecialchars((string)$r['entry_date']) ?></td>
                    <td class="small"><?= htmlspecialchars($r['source_module']) ?></td>
                    <td class="small">#<?= $r['source_reference_id'] ?></td>
                    <td><span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($r['classification']) ?></span></td>
                    <td>
                        <?php if ($r['state'] === 'SOURCE MISSING'): ?>
                        <span class="badge bg-danger-subtle text-danger">SOURCE MISSING</span>
                        <?php else: ?>
                        <span class="badge bg-info-subtle text-info">KNOWN DUMMY DATA</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a href="<?= $base ?>?page=investigation-journal&id=<?= $r['journal_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Trace</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No orphan journal references found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center small">
        <span>Page <?= $page ?> of <?= $totalPages ?></span>
        <div class="btn-group btn-group-sm">
            <?php if ($page > 1): ?><a class="btn btn-outline-secondary" href="?page=investigation-orphans&p=<?= $page - 1 ?>">Previous</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a class="btn btn-outline-secondary" href="?page=investigation-orphans&p=<?= $page + 1 ?>">Next</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="alert alert-secondary mt-3 small mb-0">
    <i class="bi bi-info-circle me-1"></i>
    KNOWN DUMMY DATA rows match the already-documented Stage 19D "orphan journal forensics" finding (a prior manual test-data cleanup operation) — not a new incident. A SOURCE MISSING row on live-classified data would be a genuine defect requiring the controlled correction workflow (a later stage); none currently exist.
</div>
