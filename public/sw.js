/* SmackChat service worker — cache static assets only; never cache Laravel HTML/API (CSRF-safe). */
const CACHE = 'smack-static-v12';
const PRECACHE = [
    '/offline.html',
    '/favicon.png',
    '/manifest.webmanifest',
    '/brand/logo-mark.png',
    '/icons/smack-192.png',
    '/icons/smack-512.png',
    '/icons/smack-apple-touch.png',
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
        || url.pathname.startsWith('/brand/')
        || url.pathname === '/favicon.png'
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

const ICON = '/icons/smack-192.png';
const RING_VIBRATE = [600, 300, 600, 300, 600, 300, 600, 300, 600, 300, 600];

function notificationOptions(payload) {
    const isCall = payload.type === 'call' || (Boolean(payload.call_id) && payload.type !== 'call-ended');

    return {
        body: payload.body || 'New message',
        icon: new URL(ICON, self.location.origin).href,
        badge: new URL(ICON, self.location.origin).href,
        tag: payload.tag || `smack-${Date.now()}`,
        data: {
            url: payload.url || '/app',
            call_id: payload.call_id || null,
            workspace: payload.workspace || null,
            kind: isCall ? 'call' : (payload.type || 'message'),
        },
        timestamp: payload.started_at || Date.now(),
        // Android keeps a call notification up until it is handled and shows
        // the Answer / Decline buttons. iPhone rejects unknown options, so the
        // push handler has a plain fallback.
        requireInteraction: isCall,
        renotify: isCall ? true : undefined,
        vibrate: isCall ? RING_VIBRATE : undefined,
        actions: isCall
            ? [
                { action: 'answer', title: 'Answer' },
                { action: 'decline', title: 'Decline' },
            ]
            : undefined,
    };
}

async function appHasFocus() {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const live = new Set(windows.map((client) => client.id));

    for (const id of viewing.keys()) {
        if (!live.has(id)) {
            viewing.delete(id);
        }
    }

    return windows.some((client) => {
        if (client.visibilityState !== 'visible') {
            return false;
        }

        return client.focused || Boolean(viewing.get(client.id)?.focused);
    });
}

async function show(title, options) {
    await self.registration.showNotification(title, options).catch(() => self.registration.showNotification(title, {
        body: options.body,
        tag: options.tag,
        data: options.data,
    }));
}

async function closeTagged(tag) {
    const open = await self.registration.getNotifications({ tag });
    open.forEach((item) => item.close());

    return open.length;
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

    // iPhone throws if it sees unsupported options such as renotify, and it
    // drops the subscription when a push does not display a notification. So
    // on iPhone every push shows something, even when the app is open; a
    // push that stayed silent there is how calls stopped arriving at all.
    const ios = /iPhone|iPad|iPod/i.test(self.navigator?.userAgent || '');
    const icon = new URL(ICON, self.location.origin).href;

    event.waitUntil((async () => {
        if (payload.type === 'call-ended') {
            const wasRinging = await closeTagged(payload.tag);

            // Someone looking at the app saw the ring stop there.
            if (!ios && await appHasFocus()) {
                return;
            }

            // Replace the ring with a quiet "Missed call" so the phone does not
            // keep showing Answer for a call nobody can join any more.
            if (ios || wasRinging || !payload.answered) {
                await show(title, {
                    body: payload.body || '',
                    icon,
                    tag: payload.tag,
                    data: { url: payload.url || '/app', kind: 'call-ended' },
                    silent: true,
                });
            }

            return;
        }

        if (payload.call_id) {
            // A push that sat in the queue past the ring window is a missed
            // call, not an incoming one.
            if (payload.expires_at && Date.now() > Number(payload.expires_at)) {
                if (ios || !(await appHasFocus())) {
                    await show('Missed call', {
                        body: payload.body || '',
                        icon,
                        tag: payload.tag,
                        data: { url: (payload.url || '/app').replace(/[?&]call=\d+/, ''), kind: 'call-ended' },
                        silent: true,
                    });
                }

                return;
            }

            if (!ios) {
                // The focused app already shows the full-screen ring. A window
                // that is merely open behind another one does not count: the
                // person is not looking at it, so the system must ring.
                if (await appHasFocus()) {
                    return;
                }

                // The open app already raised this ring through the system.
                const ringing = await self.registration.getNotifications({ tag: payload.tag });

                if (ringing.length) {
                    return;
                }
            }

            await show(title, notificationOptions(payload));

            return;
        }

        // The open tab already shows the island. A second system alert is the duplicate.
        if (!ios && await appHasFocus()) {
            return;
        }

        await show(title, notificationOptions(payload));
    })());
});

async function declineCall(data) {
    if (!data?.call_id || !data?.workspace) {
        return false;
    }

    try {
        // The app's XSRF cookie is readable here through cookieStore (Chrome
        // and Edge). Without it the request would be rejected, so fall back.
        const cookie = self.cookieStore ? await self.cookieStore.get('XSRF-TOKEN') : null;

        if (!cookie?.value) {
            return false;
        }

        const response = await fetch(`/api/workspaces/${encodeURIComponent(data.workspace)}/calls/${Number(data.call_id)}/decline`, {
            method: 'POST',
            credentials: 'include',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(cookie.value),
            },
        });

        return response.ok;
    } catch {
        return false;
    }
}

async function focusOrOpen(path) {
    const target = new URL(path, self.location.origin).href;
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const open = windows.filter((client) => {
        try {
            return new URL(client.url).origin === self.location.origin;
        } catch {
            return false;
        }
    });
    const client = open.find((item) => item.visibilityState === 'visible') || open[0];

    if (!client) {
        await self.clients.openWindow(target);
        return;
    }

    client.postMessage({ type: 'navigate', url: path });
    await client.focus();
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const data = event.notification.data || {};
    const path = data.url || '/app';

    event.waitUntil((async () => {
        if (event.action === 'decline') {
            if (await declineCall(data)) {
                return;
            }

            // No cookie access in this browser: let the app send the decline.
            const separator = path.includes('?') ? '&' : '?';
            await focusOrOpen(`${path}${separator}decline=1`);
            return;
        }

        await focusOrOpen(path);
    })());
});

// The browser can rotate a subscription while the app is closed. Save the new
// one right away, or this phone stops getting calls until the app is opened.
self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil((async () => {
        try {
            const old = event.oldSubscription;
            const key = old?.options?.applicationServerKey;
            const fresh = event.newSubscription || (key
                ? await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key })
                : null);

            if (!fresh) {
                return;
            }

            const cookie = self.cookieStore ? await self.cookieStore.get('XSRF-TOKEN') : null;
            const json = fresh.toJSON();

            await fetch('/api/push/subscription', {
                method: 'PUT',
                credentials: 'include',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(cookie?.value ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.value) } : {}),
                },
                body: JSON.stringify({
                    endpoint: json.endpoint,
                    public_key: json.keys?.p256dh,
                    auth_token: json.keys?.auth,
                    content_encoding: 'aes128gcm',
                }),
            });
        } catch {
            /* the app re-subscribes the next time it opens */
        }
    })());
});
