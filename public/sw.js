// ─────────────────────────────────────────────────────────────────────────────
// Service worker IWasThere
//
// Deux rôles :
//   1. Push (notifications) — inchangé, voir plus bas.
//   2. Mode hors-ligne — cacher l'app-shell (CSS/JS/polices) et les dernières pages
//      visitées, pour rouvrir ses souvenirs sans réseau (typiquement en festival, là
//      où le réseau est saturé). Stratégies :
//        · pages (navigations + visites Turbo) → réseau d'abord, cache en repli ;
//        · assets générés + polices Google      → cache d'abord, revalidation en fond.
//
// Bump VERSION à chaque changement de logique de cache : l'activation purge alors
// les anciens caches « iwt-* ».
// ─────────────────────────────────────────────────────────────────────────────

const VERSION = 'v1';
const PAGES_CACHE = 'iwt-pages-' + VERSION;
const ASSETS_CACHE = 'iwt-assets-' + VERSION;
const SHELL_CACHE = 'iwt-shell-' + VERSION;

const OFFLINE_URL = '/offline';

// Nombre max de pages gardées hors-ligne — assez pour un historique de navigation
// récent sans laisser le cache grossir indéfiniment.
const MAX_PAGES = 40;

// Précache minimal : la page de repli et quelques icônes à chemin stable. On reste
// court volontairement — un seul asset manquant ne doit pas faire échouer l'install
// (d'où allSettled plutôt que cache.addAll, tout-ou-rien).
const SHELL_ASSETS = [
    OFFLINE_URL,
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE)
            .then((cache) => Promise.allSettled(SHELL_ASSETS.map((url) => cache.add(url))))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    const keep = [PAGES_CACHE, ASSETS_CACHE, SHELL_CACHE];
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => k.startsWith('iwt-') && !keep.includes(k))
                    .map((k) => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

// ── Cache offline ──────────────────────────────────────────────────────────────

function isPageRequest(request) {
    return request.mode === 'navigate'
        || (request.headers.get('accept') || '').includes('text/html');
}

// Garde le cache de pages sous MAX_PAGES, en évinçant les plus anciennes (les clés
// sont renvoyées dans l'ordre d'insertion).
async function trimPages() {
    const cache = await caches.open(PAGES_CACHE);
    const keys = await cache.keys();
    for (const key of keys.slice(0, keys.length - MAX_PAGES)) {
        await cache.delete(key);
    }
}

// Réseau d'abord : on veut toujours la version fraîche quand il y a du réseau, et on
// ne bascule sur le cache que hors-ligne. On ne mémorise que les pages complètes et
// propres (200, same-origin) — jamais les redirections d'auth (302 vers /login).
async function networkFirstPage(request) {
    const cache = await caches.open(PAGES_CACHE);
    try {
        const response = await fetch(request);
        if (response && response.status === 200 && response.type === 'basic') {
            cache.put(request, response.clone());
            trimPages();
        }
        return response;
    } catch (e) {
        const cached = await cache.match(request);
        if (cached) return cached;
        const offline = await caches.match(OFFLINE_URL);
        return offline || Response.error();
    }
}

// Cache d'abord, revalidation en fond : réponse instantanée depuis le cache, mise à
// jour silencieuse pour la prochaine fois. Accepte les réponses opaques (type
// 'opaque', status 0) des polices Google, qui n'exposent pas leur statut.
async function staleWhileRevalidate(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);
    const network = fetch(request).then((response) => {
        if (response && (response.status === 200 || response.type === 'opaque')) {
            cache.put(request, response.clone());
        }
        return response;
    }).catch(() => cached);
    return cached || network;
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    const sameOrigin = url.origin === self.location.origin;

    // Pages (navigations plein écran + visites Turbo, toutes en Accept: text/html).
    if (sameOrigin && isPageRequest(request)) {
        event.respondWith(networkFirstPage(request));
        return;
    }

    // Assets générés par l'asset-mapper (chemins /assets/…, hachés donc immuables).
    if (sameOrigin && url.pathname.startsWith('/assets/')) {
        event.respondWith(staleWhileRevalidate(request, ASSETS_CACHE));
        return;
    }

    // Polices Google — pour que le shell garde sa typo hors-ligne.
    if (url.host === 'fonts.googleapis.com' || url.host === 'fonts.gstatic.com') {
        event.respondWith(staleWhileRevalidate(request, ASSETS_CACHE));
        return;
    }
});

// ── Push (notifications) ─────────────────────────────────────────────────────────

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
