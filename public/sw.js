/* SmackChat service worker — cache static assets only; never cache Laravel HTML/API (CSRF-safe). */
const CACHE = 'smack-static-v4';
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
            .catch(() => undefined)
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

const viewing = new Map();

self.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data.type !== 'viewing' || !event.source) {
        return;
    }

    viewing.set(event.source.id, {
        channelId: data.channelId ? Number(data.channelId) : null,
        threadId: data.threadId ? Number(data.threadId) : null,
        focused: Boolean(data.focused),
    });
});

function notificationOptions(payload) {
    return {
        body: payload.body || 'New message',
        icon: new URL('/icons/icon-192.png', self.location.origin).href,
        tag: payload.tag || `smack-${Date.now()}`,
        data: { url: payload.url || '/app' },
    };
}

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = {
            title: 'SmackChat',
            body: event.data ? event.data.text() : 'New message',
        };
    }

    const title = payload.title || 'SmackChat';
    const options = notificationOptions(payload);

    // iPhone throws if it sees unsupported options such as renotify, and it
    // drops the subscription when a push does not display a notification.
    event.waitUntil(
        self.registration.showNotification(title, options).catch(() => self.registration.showNotification(title, {
            body: options.body,
            tag: options.tag,
            data: options.data,
        })),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const path = event.notification.data?.url || '/app';
    const target = new URL(path, self.location.origin).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            client.postMessage({ type: 'navigate', url: path });
            if ('navigate' in client) {
                try {
                    await client.navigate(target);
                } catch {
                    /* the page handles the navigate message */
                }
            }

            return client.focus();
        }

        await self.clients.openWindow(target);
    })());
});
