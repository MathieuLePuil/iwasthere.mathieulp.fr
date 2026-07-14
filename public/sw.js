self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function(event) {
    if (!(self.Notification && self.Notification.permission === 'granted')) {
        return;
    }

    const data = event.data ? event.data.json() : {};
    const title = data.title || "IWasThere";
    const message = data.body || "Nouvelle notification";
    const icon = data.icon || "/icons/icon-192.png";

    const options = {
        body: message,
        icon: icon,
        badge: icon,
        vibrate: [200, 100, 200],
        tag: 'iwt-notification',
        renotify: true,
        requireInteraction: true,
        data: { url: data.url || '/' },
        actions: [
            { action: 'view', title: 'Voir l\'app' },
            { action: 'close', title: 'Fermer' }
        ]
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();

    if (event.action === 'close') {
        return;
    }

    const targetUrl = new URL(
        (event.notification.data && event.notification.data.url) || '/',
        self.location.origin
    ).href;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(function(clientList) {
                for (let i = 0; i < clientList.length; i++) {
                    const client = clientList[i];
                    if ('focus' in client) {
                        const focused = client.focus();
                        if ('navigate' in client) {
                            return focused.then(function(c) {
                                return (c || client).navigate(targetUrl);
                            });
                        }
                        return focused;
                    }
                }
                if (clients.openWindow) {
                    return clients.openWindow(targetUrl);
                }
            })
    );
});
