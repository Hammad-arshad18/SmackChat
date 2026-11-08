import api from '@/api';
import { ref } from 'vue';

export const pushActive = ref(false);
export const pushError = ref('');

export function isIos() {
    const agent = navigator.userAgent || '';

    return /iPad|iPhone|iPod/.test(agent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

export function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
}

export function needsHomeScreen() {
    return isIos() && !isStandalone();
}

let lastView = { channelId: null, threadId: null };
let listening = false;

export function registerWorker() {
    if (!('serviceWorker' in navigator)) {
        return Promise.resolve(null);
    }

    return navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' })
        .then((registration) => {
            registration.update().catch(() => {});

            return registration;
        })
        .catch(() => null);
}

export function reportViewing(channelId, threadId) {
    lastView = {
        channelId: channelId ? Number(channelId) : null,
        threadId: threadId ? Number(threadId) : null,
    };
    broadcastView();
}

export function isViewingMessage(channelId, parentId) {
    const focused = document.visibilityState === 'visible' && document.hasFocus();
    if (!focused || Number(lastView.channelId) !== Number(channelId)) {
        return false;
    }

    if (parentId) {
        return Number(lastView.threadId) === Number(parentId);
    }

    return true;
}

function broadcastView() {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    ensureViewListeners();
    const payload = {
        type: 'viewing',
        channelId: lastView.channelId,
        threadId: lastView.threadId,
        focused: document.visibilityState === 'visible' && document.hasFocus(),
    };
    const send = (worker) => worker?.postMessage(payload);
    send(navigator.serviceWorker.controller);
    navigator.serviceWorker.ready.then((registration) => send(registration.active)).catch(() => {});
}

function ensureViewListeners() {
    if (listening || typeof window === 'undefined') {
        return;
    }

    listening = true;
    window.addEventListener('focus', broadcastView);
    window.addEventListener('blur', broadcastView);
    document.addEventListener('visibilitychange', broadcastView);
}

function urlBase64ToUint8Array(value) {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const output = new Uint8Array(raw.length);

    for (let index = 0; index < raw.length; index += 1) {
        output[index] = raw.charCodeAt(index);
    }

    return output;
}

function keysMatch(subscription, publicKey) {
    const current = subscription.options?.applicationServerKey;
    if (!current) {
        return true;
    }

    const actual = new Uint8Array(current);
    const expected = urlBase64ToUint8Array(publicKey);
    if (actual.length !== expected.length) {
        return false;
    }

    return actual.every((byte, index) => byte === expected[index]);
}

export async function showAlert(title, options = {}) {
    if (typeof Notification === 'undefined' || Notification.permission !== 'granted') {
        return false;
    }

    const payload = {
        body: options.body || '',
        tag: options.tag || `smack-${Date.now()}`,
        icon: `${window.location.origin}/icons/icon-192.png`,
        data: options.data || {},
    };

    try {
        const registration = await navigator.serviceWorker?.ready;
        if (registration?.showNotification) {
            await registration.showNotification(title, payload);
            return true;
        }
    } catch {
        /* fall through to the page notification */
    }

    try {
        const notice = new Notification(title, payload);
        notice.onclick = () => {
            window.focus();
            if (payload.data?.url) {
                window.dispatchEvent(new CustomEvent('smack-open', { detail: { url: payload.data.url } }));
            }
            notice.close();
        };
        return true;
    } catch {
        return false;
    }
}

export async function ensurePush({ prompt = false } = {}) {
    pushError.value = '';

    if (needsHomeScreen()) {
        pushActive.value = false;
        pushError.value = 'Open the SmackChat icon on your Home Screen, then allow alerts there.';
        return false;
    }

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        pushActive.value = false;
        pushError.value = 'This phone cannot receive SmackChat alerts.';
        return false;
    }

    let permission = Notification.permission;
    if (permission === 'default' && prompt) {
        permission = await Notification.requestPermission();
    }

    if (permission !== 'granted') {
        pushActive.value = false;
        pushError.value = permission === 'denied'
            ? 'Alerts are blocked. Allow notifications for SmackChat in your phone settings.'
            : '';
        return false;
    }

    try {
        const registration = await registerWorker();
        const ready = registration ? await navigator.serviceWorker.ready : null;
        if (!ready?.pushManager) {
            pushActive.value = false;
            pushError.value = 'The alert service did not start. Close SmackChat and open it again.';
            return false;
        }

        const { data } = await api.get('/push/key');
        if (!data?.publicKey) {
            pushActive.value = false;
            pushError.value = 'The server has no alert key yet.';
            return false;
        }

        let subscription = await ready.pushManager.getSubscription();
        const applicationServerKey = urlBase64ToUint8Array(data.publicKey);

        if (subscription && !keysMatch(subscription, data.publicKey)) {
            await subscription.unsubscribe().catch(() => {});
            subscription = null;
        }

        if (!subscription) {
            subscription = await ready.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey,
            });
        }

        const json = subscription.toJSON();
        await api.put('/push/subscription', {
            endpoint: json.endpoint,
            public_key: json.keys?.p256dh,
            auth_token: json.keys?.auth,
            content_encoding: 'aes128gcm',
        });
        pushActive.value = true;
        pushError.value = '';
        broadcastView();

        return true;
    } catch (error) {
        pushActive.value = false;
        pushError.value = error?.response?.data?.message
            || (error?.name === 'NotAllowedError' ? 'Tap the green bar and allow alerts for SmackChat.' : '')
            || (error?.name === 'AbortError' ? 'Open SmackChat from its Home Screen icon, then tap this bar again.' : '')
            || 'This phone could not register for alerts.';
        return false;
    }
}

export async function disablePush() {
    pushActive.value = false;

    if (!('serviceWorker' in navigator)) {
        return;
    }

    try {
        const registration = await navigator.serviceWorker.getRegistration('/');
        const subscription = await registration?.pushManager?.getSubscription();
        if (!subscription) {
            return;
        }

        const endpoint = subscription.endpoint;
        await subscription.unsubscribe().catch(() => {});
        await api.delete('/push/subscription', { data: { endpoint } }).catch(() => {});
    } catch {
        /* leaving notifications off locally is enough */
    }
}
