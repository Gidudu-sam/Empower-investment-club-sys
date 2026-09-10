<?php
$base = APP_URL . '/index.php';
$statusBadge = [
    'STRUCTURALLY_VALID_UNTESTED' => 'bg-info-subtle text-info',
    'INVALID' => 'bg-danger-subtle text-danger',
    'UNKNOWN' => 'bg-secondary-subtle text-secondary',
];
$recoveryBadge = [
    'discovered' => 'bg-secondary-subtle text-secondary', 'structurally_valid' => 'bg-info-subtle text-info',
    'restore_tested' => 'bg-info-subtle text-info', 'verified' => 'bg-success-subtle text-success',
    'failed' => 'bg-danger-subtle text-danger', 'rejected' => 'bg-secondary-subtle text-secondary',
];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800"><i class="bi bi-arrow-repeat me-2 text-info"></i>Database Recovery</h1>
        <p class="text-muted mb-0 small">Backup inventory and isolated recovery verification.</p>
    </div>
</div>

<div class="alert alert-info d-flex align-items-start gap-2 mb-4">
    <i class="bi bi-shield-check fs-5 flex-shrink-0"></i>
    <div><strong>Recovery verification runs against an isolated database (<code><?= DatabaseRecoveryService::ISOLATED_TEST_DB ?></code>).</strong> Production data is never modified by anything on this page.</div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold">Backup Inventory</h6>
        <span class="badge bg-secondary-subtle text-secondary"><?= count($backups) ?> found</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Filename</th><th>Location</th><th>Size</th><th>Modified</th><th>Structural Status</th><th>SHA-256</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $b): ?>
                <tr>
                    <td class="fw-semibold small"><?= htmlspecialchars($b['filename']) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($b['directory']) ?>/</td>
                    <td class="small"><?= number_format($b['size_bytes'] / 1024, 1) ?> KB</td>
                    <td class="small"><?= htmlspecialchars($b['modified_at']) ?></td>
                    <td>
                        <span class="badge <?= $statusBadge[$b['structural_status']] ?? '' ?>"><?= htmlspecialchars($b['structural_status']) ?></span>
                        <?php if (!empty($b['structural_notes'])): ?>
                        <div class="small text-muted mt-1"><?= htmlspecialchars(implode(' ', $b['structural_notes'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars((string)$b['sha256']) ?>"><?= htmlspecialchars(substr((string)$b['sha256'], 0, 12)) ?>…</td>
                    <td class="text-end">
                        <?php if ($b['structural_status'] !== 'INVALID'): ?>
                        <form method="POST" action="<?= $base ?>?page=recovery-verify" class="d-inline" onsubmit="return confirm('Run an isolated restore-and-verify test for this backup? This never touches production.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="backup_id" value="<?= htmlspecialchars($b['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-play-circle"></i> Verify (Isolated)</button>
                        </form>
                        <?php else: ?>
                        <span class="text-muted small">Not restorable</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($backups)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No backup files found in backups/ or database/backups/.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0 fw-semibold">Verification History</h6></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>#</th><th>Backup</th><th>Target</th><th>Status</th><th>Actor</th><th>When</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                <tr>
                    <td class="small">#<?= $r['id'] ?></td>
                    <td class="small"><?= htmlspecialchars($r['backup_filename']) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($r['target_database']) ?></td>
                    <td><span class="badge <?= $recoveryBadge[$r['status']] ?? '' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                    <td class="small"><?= htmlspecialchars($r['actor_name'] ?? '—') ?></td>
                    <td class="small text-muted"><?= htmlspecialchars((string)$r['created_at']) ?></td>
                    <td class="text-end"><a href="<?= $base ?>?page=recovery-result&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recent)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No verification runs yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
