/*
 * SiteSentinel service worker — Browser Push (NOTIFICATIONS.md §7.3, ADR-032).
 *
 * Handles `push` (render the notification) and `notificationclick` (focus an
 * existing SiteSentinel tab or open the admin deep link). The payload carries
 * summary + admin link only — never subscription material or channel secrets.
 */

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'SiteSentinel', body: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'SiteSentinel';
    const options = {
        body: data.body || '',
        tag: data.tag || undefined,
        data: {
            url: data.url || '/admin',
            incidentId: data.incident_id || null,
        },
        icon: '/favicon.ico',
        badge: '/favicon.ico',
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || '/admin';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            for (const client of clientList) {
                if ('focus' in client) {
                    client.navigate(target);
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(target);
            }
            return undefined;
        }),
    );
});
