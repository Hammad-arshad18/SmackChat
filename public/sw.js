/* SmackChat service worker — cache static assets only; never cache Laravel HTML/API (CSRF-safe). */
const CACHE = 'smack-static-v1';
const PRECACHE = [
    '/offline.html',
    '/favicon.svg',
    '/manifest.webmanifest',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key.startsWith('smack-') && key !== CACHE)
                    .map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

function isApiOrAuth(url) {
    return url.pathname.startsWith('/api')
        || url.pathname.startsWith('/sanctum')
        || url.pathname.startsWith('/up');
}

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin || isApiOrAuth(url)) {
        return;
    }

    // Navigations: always network; offline fallback page only (never stale Blade/CSRF).
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match('/offline.html')),
        );
        return;
    }

    // Fingerprinted Vite assets + icons: cache-first.
    const staticAsset = url.pathname.startsWith('/build/assets/')
        || url.pathname.startsWith('/icons/')
        || url.pathname === '/favicon.svg'
        || url.pathname === '/manifest.webmanifest'
        || url.pathname === '/offline.html';

    if (!staticAsset) {
        return;
    }

    event.respondWith(
        caches.open(CACHE).then(async (cache) => {
            const cached = await cache.match(request);
            if (cached) {
                return cached;
            }

            const response = await fetch(request);
            if (response.ok) {
                cache.put(request, response.clone());
            }
            return response;
        }),
    );
});
