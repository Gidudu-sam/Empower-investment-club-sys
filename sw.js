/**
 * Empower service worker — Stage 12-D.
 *
 * Scope is deliberately the whole app (this file lives at the project
 * root, alongside index.php, not under /public/ -- a service worker's
 * default max scope is the directory it is served from, and every page
 * in this app is served from this same root, e.g.
 * /empower/index.php?page=..., not from /empower/public/).
 *
 * This worker does exactly two things: display a push notification it
 * receives, and route a click on that notification back into the
 * application. It has no offline cache, no background sync, and makes
 * no decisions about what notifications should exist -- that remains
 * entirely the server's job (Stage 12-B/12-D).
 */

self.addEventListener('push', function (event) {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        // Never let a malformed/unexpected payload crash the worker.
        data = {};
    }

    const title = typeof data.title === 'string' && data.title ? data.title : 'Empower notification';
    const body = typeof data.body === 'string' ? data.body : 'You have a new notification.';

    event.waitUntil(
        self.registration.showNotification(title, {
            body: body,
            icon: '/empower/public/images/logo.png',
            badge: '/empower/public/images/logo.png',
            data: {
                // Only ever the exact action_url the server already
                // validated as same-origin (PushDeliveryService::safeActionUrl) --
                // this worker does not itself trust or re-derive it further,
                // but still re-checks same-origin below before ever navigating.
                actionUrl: typeof data.action_url === 'string' ? data.action_url : null,
                notificationId: data.notification_id || null,
            },
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    const requested = event.notification.data && event.notification.data.actionUrl;
    const fallback = self.registration.scope; // the app's own root, always safe
    let destination = fallback;

    if (requested) {
        try {
            const requestedUrl = new URL(requested, self.location.origin);
            if (requestedUrl.origin === self.location.origin) {
                destination = requestedUrl.href;
            }
        } catch (e) {
            // Malformed URL -- fall back to the app root rather than navigating anywhere unexpected.
        }
    }

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windowClients) {
            for (const client of windowClients) {
                // Reuse an existing Empower tab if one is open, focusing and
                // navigating it, rather than piling up new windows.
                if (client.url.startsWith(self.registration.scope) && 'focus' in client) {
                    client.navigate(destination).catch(function () {});
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(destination);
            }
        })
    );
});
