const CACHE_NAME = 'iwt-v5';
const STATIC_ASSETS = [
    '/',
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE_NAME).then((c) => c.addAll(STATIC_ASSETS)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET' || url.origin !== location.origin) return;

    if (
        url.pathname.startsWith('/icons/') ||
        url.pathname.startsWith('/uploads/') ||
        url.pathname.match(/\.(png|jpg|jpeg|webp|gif|svg|woff2?|css|js)$/)
    ) {
        event.respondWith(
            caches.match(event.request).then(
                (cached) => cached || fetch(event.request).then((resp) => {
                    const clone = resp.clone();
                    caches.open(CACHE_NAME).then((c) => c.put(event.request, clone));
                    return resp;
                })
            )
        );
        return;
    }

    event.respondWith(fetch(event.request).catch(() => caches.match(event.request)));
});

self.addEventListener('push', (event) => {
    if (!(self.Notification && self.Notification.permission === 'granted')) return;

    const data = event.data ? event.data.json() : {};
    const title = data.title || 'IWasThere';
    const body = data.body || '';
    const url = data.url || '/';

    const options = {
        body,
        icon: '/icons/icon-192.png',
        badge: '/icons/icon-96.png',
        vibrate: [180, 90, 180],
        tag: 'iwt-notification',
        renotify: true,
        requireInteraction: true,
        data: { url },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = event.notification.data?.url || '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
            for (const client of list) {
                if ('focus' in client) {
                    client.navigate?.(target);
                    return client.focus();
                }
            }
            if (clients.openWindow) return clients.openWindow(target);
        })
    );
});
