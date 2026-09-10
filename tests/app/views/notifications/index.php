<?php
$base = APP_URL . '/index.php';
$categoryLabels = $categoryLabels ?? [];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-bell-fill me-2 text-warning" aria-hidden="true"></i>Notifications
        </h1>
        <p class="text-muted mb-0 small"><?= number_format($total) ?> total · <?= number_format($unreadCount) ?> unread</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (!empty($canViewAudit)): ?>
        <a href="<?= $base ?>?page=notification-audit" class="btn btn-outline-info btn-sm">
            <i class="bi bi-clipboard-data me-1" aria-hidden="true"></i>Notification Audit
        </a>
        <?php endif; ?>
        <?php if ($unreadCount > 0): ?>
        <form method="POST" action="<?= $base ?>?page=notification-mark-all" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <button type="submit" class="btn btn-outline-success btn-sm">
                <i class="bi bi-check-all me-1" aria-hidden="true"></i>Mark All Read
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3" id="push-settings-card">
    <div class="card-body p-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <div class="fw-semibold small"><i class="bi bi-phone-vibrate me-2" aria-hidden="true"></i>Push Notifications</div>
            <div class="text-muted" style="font-size:.75rem;" id="push-status-text">Checking this browser's support…</div>
        </div>
        <div>
            <button type="button" class="btn btn-sm btn-outline-primary d-none" id="push-enable-btn">Enable</button>
            <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="push-disable-btn">Disable</button>
        </div>
    </div>
</div>
<script>
(function () {
    const statusText = document.getElementById('push-status-text');
    const enableBtn = document.getElementById('push-enable-btn');
    const disableBtn = document.getElementById('push-disable-btn');

    function refresh() {
        if (!window.EmpowerPush || !EmpowerPush.isSupported()) {
            statusText.textContent = 'Push notifications are not supported by this browser.';
            return;
        }
        EmpowerPush.isEnabled().then(function (enabled) {
            if (enabled) {
                statusText.textContent = 'Enabled — this device is registered to receive push notifications.';
                enableBtn.classList.add('d-none');
                disableBtn.classList.remove('d-none');
            } else {
                statusText.textContent = 'Receive important Empower notifications on this device even when the notification centre is not open.';
                disableBtn.classList.add('d-none');
                enableBtn.classList.remove('d-none');
            }
        });
    }

    enableBtn.addEventListener('click', function () {
        enableBtn.disabled = true;
        EmpowerPush.enable()
            .then(refresh)
            .catch(function (e) { statusText.textContent = e.message || 'Could not enable push notifications.'; })
            .finally(function () { enableBtn.disabled = false; });
    });
    disableBtn.addEventListener('click', function () {
        disableBtn.disabled = true;
        EmpowerPush.disable()
            .then(refresh)
            .catch(function () { statusText.textContent = 'Could not disable push notifications.'; })
            .finally(function () { disableBtn.disabled = false; });
    });

    // EmpowerPush is defined by a script block further down the layout
    // (this page's own content is included before it) -- by the time
    // DOMContentLoaded fires, every inline script has already run,
    // regardless of DOM order, so this is reliable rather than a guessed delay.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refresh);
    } else {
        refresh();
    }
})();
</script>

<?php if ($success = Session::flash('success')): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
<?php if ($error = Session::flash('error')): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i><div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" action="<?= $base ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="notifications">
            <div class="col-6 col-md-3">
                <label for="notif-filter" class="form-label small mb-1">Status</label>
                <select id="notif-filter" name="filter" class="form-select form-select-sm">
                    <option value="">All Notifications</option>
                    <option value="unread" <?= $filter === 'unread' ? 'selected' : '' ?>>Unread Only</option>
                    <option value="read" <?= $filter === 'read' ? 'selected' : '' ?>>Read Only</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="notif-type" class="form-label small mb-1">Category</label>
                <select id="notif-type" name="type" class="form-select form-select-sm">
                    <option value="">All Categories</option>
                    <?php foreach ($categoryLabels as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>" <?= $refType === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
            </div>
            <div class="col-auto">
                <a href="<?= $base ?>?page=notifications" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Notifications List -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($notifications)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-bell-slash fs-1 d-block mb-2 opacity-25" aria-hidden="true"></i>
            <p class="mb-0">No notifications found.</p>
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($notifications as $n):
                $iconClass = match($n['type']) {
                    'success'  => 'bi-check-circle-fill text-success',
                    'warning'  => 'bi-exclamation-triangle-fill text-warning',
                    'critical' => 'bi-x-octagon-fill text-danger',
                    default    => 'bi-info-circle-fill text-info',
                };
                $isUnread = !$n['is_read'];
                $bgClass = $isUnread ? 'bg-light' : '';
                // Prefer the trusted, server-generated action_url (Stage 12-B)
                // over reconstructing a link from reference_type -- but older
                // rows predating that column fall back to the exact same
                // reference_type-based links this page always used, so
                // nothing that used to work stops working.
                $actionUrl = $n['action_url'] ?? null;
                if (!$actionUrl && in_array($n['reference_type'], ['loan', 'loan_overdue'], true)) {
                    $actionUrl = $base . '?page=loan-view&id=' . (int)$n['reference_id'];
                } elseif (!$actionUrl && $n['reference_type'] === 'internal_voucher') {
                    $actionUrl = $base . '?page=internal-voucher-view&id=' . (int)$n['reference_id'];
                }
            ?>
            <div class="list-group-item <?= $bgClass ?> px-4 py-3">
                <div class="d-flex align-items-start gap-3">
                    <div class="flex-shrink-0 mt-1" aria-hidden="true">
                        <i class="bi <?= $iconClass ?> fs-5"></i>
                    </div>
                    <div class="flex-grow-1" style="min-width:0;">
                        <div class="d-flex align-items-center justify-content-between mb-1 gap-2">
                            <h6 class="mb-0 fw-semibold small text-truncate <?= $isUnread ? '' : 'text-muted' ?>">
                                <?php if ($isUnread): ?><span class="d-inline-block rounded-circle bg-primary me-1" style="width:6px;height:6px;" aria-label="Unread"></span><?php endif; ?>
                                <?= htmlspecialchars($n['title']) ?>
                            </h6>
                            <small class="text-muted text-nowrap"><?= date('d M Y H:i', strtotime($n['created_at'])) ?></small>
                        </div>
                        <p class="mb-1 small <?= $isUnread ? 'text-dark' : 'text-muted' ?>">
                            <?= htmlspecialchars($n['message']) ?>
                        </p>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="badge rounded-pill <?= match($n['type']){
                                'success'  => 'bg-success-subtle text-success',
                                'warning'  => 'bg-warning-subtle text-warning',
                                'critical' => 'bg-danger-subtle text-danger',
                                default    => 'bg-info-subtle text-info',
                            } ?>" style="font-size:.65rem"><?= ucfirst($n['type']) ?></span>

                            <?php if (!empty($n['priority']) && $n['priority'] === 'high'): ?>
                            <span class="badge rounded-pill bg-danger-subtle text-danger" style="font-size:.65rem">High Priority</span>
                            <?php endif; ?>

                            <?php if ($n['reference_type']): ?>
                            <span class="badge bg-secondary-subtle text-secondary rounded-pill" style="font-size:.65rem">
                                <?= htmlspecialchars($categoryLabels[$n['reference_type']] ?? ucfirst(str_replace('_', ' ', $n['reference_type']))) ?>
                            </span>
                            <?php endif; ?>

                            <?php if ($isUnread): ?>
                            <form method="POST" action="<?= $base ?>?page=notification-read" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:.7rem">
                                    <i class="bi bi-check" aria-hidden="true"></i> Mark Read
                                </button>
                            </form>
                            <?php endif; ?>

                            <?php if ($actionUrl): ?>
                            <a href="<?= htmlspecialchars($actionUrl) ?>" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.7rem">
                                <i class="bi bi-eye" aria-hidden="true"></i> View
                            </a>
                            <?php endif; ?>

                            <?php if (!$n['is_broadcast']): ?>
                            <form method="POST" action="<?= $base ?>?page=notification-delete" class="d-inline"
                                  onsubmit="return confirm('Delete this notification?')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.7rem" aria-label="Delete notification">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<nav class="mt-4" aria-label="Notifications pagination">
    <ul class="pagination pagination-sm justify-content-center flex-wrap">
        <?php for ($p = 1; $p <= min($pages, 10); $p++): ?>
        <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
            <a class="page-link" href="<?= $base ?>?page=notifications&p=<?= $p ?>&filter=<?= urlencode($filter) ?>&type=<?= urlencode($refType) ?>" <?= $p === $currentPage ? 'aria-current="page"' : '' ?>>
                <?= $p ?>
            </a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>
