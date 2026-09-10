<?php
$base = APP_URL . '/index.php';
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-database me-2 text-info"></i>Database Management
        </h1>
        <p class="text-muted mb-0 small">Backup and restore the system database</p>
    </div>
    <form method="POST" action="<?= $base ?>?page=settings-db-backup" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <button type="submit" class="btn btn-success btn-sm"
                onclick="return confirm('Create a new database backup?')">
            <i class="bi bi-download me-1"></i>Backup Now
        </button>
    </form>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php include VIEW_PATH . '/settings/partials/nav-tabs.php'; ?>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-clock-history me-2"></i>Backup History</h6>
        <span class="badge bg-info-subtle text-info"><?= count($backups) ?> backups</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Filename</th>
                    <th>Size</th>
                    <th>Created By</th>
                    <th>Date</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($backups)): ?>
                <tr><td colspan="6" class="text-center py-4 text-muted">
                    <i class="bi bi-database fs-2 d-block mb-2 opacity-25"></i>
                    No backups yet. Click "Backup Now" to create your first backup.
                </td></tr>
                <?php else: foreach ($backups as $i => $b): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                    <td class="fw-semibold small"><i class="bi bi-file-earmark-zip me-1 text-success"></i><?= htmlspecialchars($b['filename']) ?></td>
                    <td class="small text-muted">
                        <?php
                        $size = (int)$b['file_size'];
                        if ($size >= 1048576) echo number_format($size / 1048576, 2) . ' MB';
                        elseif ($size >= 1024) echo number_format($size / 1024, 1) . ' KB';
                        else echo $size . ' B';
                        ?>
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars($b['created_by_name'] ?? 'System') ?></td>
                    <td class="small text-muted"><?= date('d M Y H:i', strtotime($b['created_at'])) ?></td>
                    <td class="text-center">
                        <a href="<?= $base ?>?page=settings-db-download&file=<?= urlencode($b['filename']) ?>"
                           class="btn btn-sm btn-outline-primary" title="Download">
                            <i class="bi bi-download"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="alert alert-warning d-flex align-items-start gap-2 mt-4">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
    <div class="small">
        <strong>Important:</strong> Database backups contain all system data including user credentials.
        Store backup files securely and never share them publicly.
    </div>
</div>
