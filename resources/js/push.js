import api from '@/api';
import { ref } from 'vue';

export const pushActive = ref(false);

let lastView = { channelId: null, threadId: null };
let listening = false;

export function registerWorker() {
    if (!('serviceWorker' in navigator)) {
        return Promise.resolve(null);
    }

    return navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => null);
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

export async function ensurePush({ prompt = false } = {}) {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        pushActive.value = false;
        return false;
    }

    let permission = Notification.permission;
    if (permission === 'default' && prompt) {
        permission = await Notification.requestPermission();
    }

    if (permission !== 'granted') {
        pushActive.value = false;
        return false;
    }

    try {
        const registration = await registerWorker();
        const ready = registration ? await navigator.serviceWorker.ready : null;
        if (!ready) {
            pushActive.value = false;
            return false;
        }

        const { data } = await api.get('/push/key');
        if (!data?.publicKey) {
            pushActive.value = false;
            return false;
        }

        let subscription = await ready.pushManager.getSubscription();
        const applicationServerKey = urlBase64ToUint8Array(data.publicKey);

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
        broadcastView();

        return true;
    } catch {
        pushActive.value = false;
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
