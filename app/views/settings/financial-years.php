<?php
$base = APP_URL . '/index.php';
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-calendar-range me-2 text-primary"></i>Financial Years
        </h1>
        <p class="text-muted mb-0 small">Manage financial year periods</p>
    </div>
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

<div class="row g-4">
    <!-- Create/Edit Form -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-plus-circle me-2"></i>Add Financial Year</h6></div>
            <div class="card-body p-4">
                <form method="POST" action="<?= $base ?>?page=settings-fy-save">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="id" value="0">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. FY 2025/2026" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Start Date</label>
                        <input type="date" name="start_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">End Date</label>
                        <input type="date" name="end_date" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-semibold">
                        <i class="bi bi-floppy me-2"></i>Save Financial Year
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- List -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">All Financial Years</h6>
                <span class="badge bg-primary-subtle text-primary"><?= count($years) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Name</th>
                            <th>Start</th>
                            <th>End</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($years)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No financial years created yet.</td></tr>
                        <?php else: foreach ($years as $fy): ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?= htmlspecialchars($fy['name']) ?></td>
                            <td class="small text-muted"><?= date('d M Y', strtotime($fy['start_date'])) ?></td>
                            <td class="small text-muted"><?= date('d M Y', strtotime($fy['end_date'])) ?></td>
                            <td class="text-center">
                                <span class="badge rounded-pill <?= match($fy['status']){
                                    'active'  => 'bg-success-subtle text-success',
                                    'closed'  => 'bg-secondary-subtle text-secondary',
                                    default   => 'bg-warning-subtle text-warning'
                                } ?>"><?= ucfirst($fy['status']) ?></span>
                            </td>
                            <td class="text-center">
                                <?php if ($fy['status'] !== 'active'): ?>
                                <a href="<?= $base ?>?page=settings-fy-activate&id=<?= $fy['id'] ?>"
                                   class="btn btn-sm btn-outline-success" title="Activate"
                                   onclick="return confirm('Activate this financial year?')">
                                    <i class="bi bi-check-circle"></i>
                                </a>
                                <?php endif; ?>
                                <?php if ($fy['status'] === 'active'): ?>
                                <a href="<?= $base ?>?page=settings-fy-close&id=<?= $fy['id'] ?>"
                                   class="btn btn-sm btn-outline-danger" title="Close"
                                   onclick="return confirm('Close this financial year?')">
                                    <i class="bi bi-x-circle"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
