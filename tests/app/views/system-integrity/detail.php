<?php
$base = APP_URL . '/index.php';
$badgeClass = [
    'PASS' => 'bg-success-subtle text-success',
    'WARNING' => 'bg-warning-subtle text-warning',
    'ERROR' => 'bg-danger-subtle text-danger',
    'UNAVAILABLE' => 'bg-secondary-subtle text-secondary',
];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h4 mb-1 fw-bold text-gray-800">
            <i class="bi bi-search me-2 text-info"></i><?= htmlspecialchars($result['title']) ?>
        </h1>
        <p class="text-muted mb-0 small"><?= htmlspecialchars($result['message']) ?></p>
    </div>
    <a href="<?= $base ?>?page=system-integrity" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to System Integrity
    </a>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">Detail Rows</h6>
        <span class="badge <?= $badgeClass[$result['status']] ?>"><?= $result['status'] ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped mb-0">
            <thead class="table-light">
                <tr>
                    <?php
                    $allKeys = [];
                    foreach ($result['details'] as $row) { $allKeys = array_merge($allKeys, array_keys($row)); }
                    $allKeys = array_values(array_unique($allKeys));
                    foreach ($allKeys as $k): ?>
                    <th class="text-capitalize"><?= htmlspecialchars(str_replace('_', ' ', $k)) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($result['details'] as $row): ?>
                <tr>
                    <?php foreach ($allKeys as $k):
                        $v = $row[$k] ?? '';
                        if (is_array($v)) { $v = implode(', ', $v); }
                    ?>
                    <td class="small"><?= htmlspecialchars((string)$v) ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($result['details'])): ?>
                <tr><td colspan="<?= max(count($allKeys), 1) ?>" class="text-center text-muted py-4">No detail rows.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="alert alert-secondary mt-3 small mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Read-only. Requires investigation/correction workflow (a later stage) if action is needed — nothing on this page repairs, reverses, or posts anything.
</div>
