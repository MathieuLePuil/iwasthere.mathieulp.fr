const CACHE_NAME = 'iwt-v1';
const STATIC_ASSETS = [
  '/',
  '/manifest.json',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
];

// Install: pre-cache critical assets
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
  );
  self.skipWaiting();
});

// Activate: clean up old caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// Fetch: network-first for HTML/API, cache-first for static assets
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Skip non-GET and cross-origin
  if (event.request.method !== 'GET' || url.origin !== location.origin) return;

  // Cache-first for static assets (images, fonts, icons)
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

  // Network-first for everything else (pages, API)
  event.respondWith(
    fetch(event.request).catch(() => caches.match(event.request))
  );
});

// Push notifications
self.addEventListener('push', (event) => {
  const data = event.data?.json() ?? {};
  event.waitUntil(
    self.registration.showNotification(data.title ?? 'IWasThere', {
      body: data.body ?? '',
      icon: '/icons/icon-192.png',
      badge: '/icons/icon-96.png',
      data: { url: data.url ?? '/' },
    })
  );
});

// Notification click → open or focus the app
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = event.notification.data?.url ?? '/';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      const existing = list.find((c) => c.url.includes(location.origin));
      return existing ? existing.focus() : clients.openWindow(target);
    })
  );
});
