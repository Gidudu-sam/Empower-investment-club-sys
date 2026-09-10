<?php
$base = APP_URL . '/index.php';
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-journal-text me-2 text-dark"></i>Audit Logs
        </h1>
        <p class="text-muted mb-0 small">Complete system activity trail</p>
    </div>
</div>

<?php include VIEW_PATH . '/settings/partials/nav-tabs.php'; ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" action="<?= $base ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="settings-audit">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Search by user, description, IP..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="action" class="form-select form-select-sm">
                    <option value="">All Actions</option>
                    <?php
                    $actions = ['login','logout','savings_created','savings_deleted','loan_created',
                                'repayment_recorded','withdrawal_processed','settings_updated',
                                'user_created','user_updated','user_toggled','password_reset',
                                'report_generated','report_viewed','database_backup',
                                'financial_year_saved','financial_year_activated','financial_year_closed',
                                'receipt_printed','statement_generated'];
                    foreach ($actions as $a):
                    ?>
                    <option value="<?= $a ?>" <?= $action === $a ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $a)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
            </div>
            <div class="col-auto">
                <a href="<?= $base ?>?page=settings-audit" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Logs Table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">Activity Log</h6>
        <span class="badge bg-dark"><?= number_format($total) ?> total entries</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Date & Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Description</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="5" class="text-center py-4 text-muted">No activity logs found.</td></tr>
                <?php else: foreach ($logs as $log): ?>
                <tr>
                    <td class="ps-3 small text-muted"><?= date('d M Y H:i:s', strtotime($log['created_at'])) ?></td>
                    <td class="fw-semibold small"><?= htmlspecialchars($log['user_name'] ?? 'System') ?></td>
                    <td>
                        <span class="badge rounded-pill bg-primary-subtle text-primary small">
                            <?= htmlspecialchars($log['action']) ?>
                        </span>
                    </td>
                    <td class="small text-muted" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= htmlspecialchars($log['description'] ?? '—') ?>
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<nav class="mt-4">
    <ul class="pagination pagination-sm justify-content-center">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
        <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
            <a class="page-link" href="<?= $base ?>?page=settings-audit&p=<?= $p ?>&search=<?= urlencode($search) ?>&action=<?= urlencode($action) ?>">
                <?= $p ?>
            </a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>
