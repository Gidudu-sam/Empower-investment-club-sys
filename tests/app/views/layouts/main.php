<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?></title>

    <!-- Google Fonts: Space Grotesk + Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Custom styles -->
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/app.css?v=<?= time() ?>">
    <!-- Stage 12-D: PWA / push notification foundation -->
    <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
    <meta name="theme-color" content="#0b1f3a">
</head>
<body>

    <?php include VIEW_PATH . '/layouts/navbar.php'; ?>

    <div id="layoutSidenav">

        <?php include VIEW_PATH . '/layouts/sidebar.php'; ?>

        <div id="layoutSidenav_content">
            <main>
                <div class="container-fluid px-3 py-2">
                    <?php include VIEW_PATH . '/layouts/page-header.php'; ?>
                    <?= $content ?>
                </div>
            </main>
            <?php include VIEW_PATH . '/layouts/footer.php'; ?>
        </div>

    </div><!-- /#layoutSidenav -->

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom scripts -->
    <script src="<?= APP_URL ?>/public/js/app.js"></script>
    <!-- Notification Bell Script (Stage 12-C) -->
    <script>
    (function(){
        const appUrl = '<?= APP_URL ?>';
        const badge = document.getElementById('notif-badge');
        const notifList = document.getElementById('notifList');
        const markAllBtn = document.getElementById('markAllBtn');
        const notifItem = document.querySelector('.nav-item.dropdown[data-csrf-token]');
        const csrfToken = notifItem ? notifItem.getAttribute('data-csrf-token') : '';

        // Notification title/message can contain user-supplied text (e.g. a
        // voucher rejection reason) -- escape before inserting via
        // innerHTML so the dropdown can never execute injected markup.
        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str == null ? '' : String(str);
            return div.innerHTML;
        }

        function postAction(page, body) {
            const params = new URLSearchParams(Object.assign({csrf_token: csrfToken}, body));
            return fetch(appUrl + '/index.php?page=' + page, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: params.toString()
            }).then(r => r.json());
        }

        function fetchNotifications() {
            fetch(appUrl + '/index.php?page=notification-fetch', {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(r => r.json())
            .then(data => {
                // Update badge -- server-computed, recipient-scoped count only
                // (Stage 12-B's unreadCount(), never derived from the DOM).
                if (data.unread > 0) {
                    badge.textContent = data.unread > 99 ? '99+' : data.unread;
                    badge.classList.remove('d-none');
                    if (markAllBtn) markAllBtn.style.display = '';
                } else {
                    badge.classList.add('d-none');
                    if (markAllBtn) markAllBtn.style.display = 'none';
                }

                // Update dropdown list
                if (data.notifications && data.notifications.length > 0) {
                    let html = '';
                    data.notifications.forEach(n => {
                        const icon = {
                            'success': 'bi-check-circle-fill text-success',
                            'warning': 'bi-exclamation-triangle-fill text-warning',
                            'critical': 'bi-x-octagon-fill text-danger',
                            'info': 'bi-info-circle-fill text-info'
                        }[n.type] || 'bi-info-circle-fill text-info';
                        const unread = n.is_read == 0;
                        const bg = unread ? 'background:#f8f9fa;' : '';
                        const dot = unread ? '<span class="d-inline-block rounded-circle bg-primary me-1" style="width:6px;height:6px;"></span>' : '';
                        const time = new Date(n.created_at).toLocaleDateString('en-GB', {day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'});
                        html += `<div class="dropdown-item px-3 py-2 border-bottom notif-row" style="${bg}cursor:pointer;" data-id="${n.id}" data-url="${escapeHtml(n.action_url || '')}" data-unread="${unread ? '1' : '0'}">
                            <div class="d-flex align-items-start gap-2">
                                <i class="bi ${icon} flex-shrink-0 mt-1"></i>
                                <div style="min-width:0;">
                                    <div class="fw-semibold small text-truncate">${dot}${escapeHtml(n.title)}</div>
                                    <div class="text-muted" style="font-size:.7rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(n.message)}</div>
                                    <div class="text-muted" style="font-size:.65rem;">${time}</div>
                                </div>
                            </div>
                        </div>`;
                    });
                    notifList.innerHTML = html;

                    // Clicking a row marks it read (if unread) then navigates
                    // to its trusted, server-generated action_url, if any --
                    // the browser never supplies user_id; ownership is
                    // resolved server-side from the session exactly as
                    // every other notification mutation already is.
                    notifList.querySelectorAll('.notif-row').forEach(row => {
                        row.addEventListener('click', function () {
                            const id = this.getAttribute('data-id');
                            const url = this.getAttribute('data-url');
                            const go = () => { if (url) { window.location.href = url; } else { window.location.href = appUrl + '/index.php?page=notifications'; } };
                            if (this.getAttribute('data-unread') === '1') {
                                postAction('notification-read', {id: id}).then(() => go()).catch(() => go());
                            } else {
                                go();
                            }
                        });
                    });
                } else {
                    notifList.innerHTML = '<div class="text-center py-3 text-muted small"><i class="bi bi-check-circle d-block fs-2 mb-1 opacity-25"></i>No new notifications</div>';
                }
            })
            .catch(() => {});
        }

        if (markAllBtn) {
            markAllBtn.addEventListener('click', function () {
                postAction('notification-mark-all', {}).then(() => fetchNotifications()).catch(() => {});
            });
        }

        // Fetch on page load and every 60 seconds. This is a read/display
        // poll only -- it asks the server for already-generated
        // notifications, it does not itself decide who gets notified.
        if (badge && notifList) {
            fetchNotifications();
            setInterval(fetchNotifications, 60000);
        }
    })();
    </script>

    <!-- Stage 12-D: Push notification registration (progressive enhancement --
         the notification bell/centre above already works fully without any
         of this; push is purely an optional secondary delivery channel). -->
    <script>
    (function () {
        const appUrl = '<?= APP_URL ?>';
        const notifItem = document.querySelector('.nav-item.dropdown[data-csrf-token]');
        const csrfToken = notifItem ? notifItem.getAttribute('data-csrf-token') : '';
        const supported = ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);

        function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) { outputArray[i] = rawData.charCodeAt(i); }
            return outputArray;
        }

        function postForm(page, body) {
            const params = new URLSearchParams(Object.assign({ csrf_token: csrfToken }, body));
            return fetch(appUrl + '/index.php?page=' + page, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString(),
            }).then(r => r.json());
        }

        async function currentSubscription() {
            const reg = await navigator.serviceWorker.ready;
            return reg.pushManager.getSubscription();
        }

        async function enable() {
            if (Notification.permission === 'denied') {
                throw new Error('Notifications are blocked for this site in your browser settings.');
            }
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                throw new Error('Permission was not granted.');
            }
            const keyResp = await fetch(appUrl + '/index.php?page=push-vapid-key');
            const keyData = await keyResp.json();
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(keyData.publicKey),
            });
            const result = await postForm('push-subscribe', { subscription: JSON.stringify(sub.toJSON()) });
            if (!result.success) { throw new Error(result.error || 'Could not register this device.'); }
            return true;
        }

        async function disable() {
            const sub = await currentSubscription();
            if (sub) {
                await postForm('push-unsubscribe', { endpoint: sub.endpoint });
                await sub.unsubscribe();
            }
            return true;
        }

        window.EmpowerPush = {
            isSupported: () => supported,
            isEnabled: async () => supported && !!(await currentSubscription()),
            enable: enable,
            disable: disable,
        };

        if (supported) {
            navigator.serviceWorker.register(appUrl + '/sw.js', { scope: appUrl.replace(/^https?:\/\/[^/]+/, '') + '/' })
                .catch(function () { /* registration failure must never break the rest of the app */ });
        }
    })();
    </script>
</body>
</html>
