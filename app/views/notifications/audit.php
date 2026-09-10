<?php
$base = APP_URL . '/index.php';
$categoryLabels = $categoryLabels ?? [];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-clipboard-data-fill me-2 text-info" aria-hidden="true"></i>Notification Audit
        </h1>
        <p class="text-muted mb-0 small">
            <?= number_format($total) ?> notification event(s) system-wide · read-only operational view
        </p>
    </div>
</div>

<div class="alert alert-secondary small mb-3">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
    This view shows every notification event across all users, for delivery/operational diagnostics only.
    It cannot mark notifications read or delete them — use <a href="<?= $base ?>?page=notifications">My Notifications</a> for that.
</div>

<!-- Filter -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" action="<?= $base ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="notification-audit">
            <div class="col-6 col-md-3">
                <label for="audit-type" class="form-label small mb-1">Event Type</label>
                <select id="audit-type" name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <?php foreach ($categoryLabels as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>" <?= $refType === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
            </div>
            <div class="col-auto">
                <a href="<?= $base ?>?page=notification-audit" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($notifications)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-clipboard-x fs-1 d-block mb-2 opacity-25" aria-hidden="true"></i>
            <p class="mb-0">No notification events found.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Created</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Recipient</th>
                        <th>Role (current)</th>
                        <th>Read?</th>
                        <th>Push</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notifications as $n): ?>
                    <tr>
                        <td class="text-nowrap small"><?= htmlspecialchars(date('d M Y H:i', strtotime($n['created_at']))) ?></td>
                        <td>
                            <div class="small fw-semibold"><?= htmlspecialchars($n['title']) ?></div>
                            <?php if ($n['reference_type']): ?>
                            <span class="badge bg-secondary-subtle text-secondary rounded-pill" style="font-size:.65rem">
                                <?= htmlspecialchars($categoryLabels[$n['reference_type']] ?? ucfirst(str_replace('_', ' ', $n['reference_type']))) ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge rounded-pill <?= match($n['type']){
                                'success'  => 'bg-success-subtle text-success',
                                'warning'  => 'bg-warning-subtle text-warning',
                                'critical' => 'bg-danger-subtle text-danger',
                                default    => 'bg-info-subtle text-info',
                            } ?>" style="font-size:.65rem"><?= ucfirst($n['type']) ?></span>
                        </td>
                        <td class="small">
                            <?php if ($n['is_broadcast']): ?>
                                <span class="text-muted fst-italic">All users (broadcast)</span>
                            <?php elseif ($n['recipient_name']): ?>
                                <?= htmlspecialchars($n['recipient_name']) ?>
                            <?php elseif ($n['user_id']): ?>
                                <span class="text-muted">User #<?= (int)$n['user_id'] ?> (no longer exists)</span>
                            <?php else: ?>
                                <span class="text-danger fst-italic">Unreachable — no user_id, not broadcast (legacy row)</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars($n['recipient_role_current'] ?? ($n['recipient_role'] ?? '—')) ?></td>
                        <td>
                            <?php if ($n['is_read']): ?>
                                <span class="badge bg-success-subtle text-success" style="font-size:.65rem">Read</span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary" style="font-size:.65rem">Unread</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $pushBadge = match($n['push_status'] ?? 'not attempted') {
                                'delivered' => 'bg-success-subtle text-success',
                                'failed'    => 'bg-danger-subtle text-danger',
                                default     => 'bg-light text-muted',
                            }; ?>
                            <span class="badge <?= $pushBadge ?>" style="font-size:.65rem"><?= htmlspecialchars(ucfirst($n['push_status'] ?? 'not attempted')) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<nav class="mt-4" aria-label="Notification audit pagination">
    <ul class="pagination pagination-sm justify-content-center flex-wrap">
        <?php for ($p = 1; $p <= min($pages, 10); $p++): ?>
        <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
            <a class="page-link" href="<?= $base ?>?page=notification-audit&p=<?= $p ?>&type=<?= urlencode($refType) ?>" <?= $p === $currentPage ? 'aria-current="page"' : '' ?>>
                <?= $p ?>
            </a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>
