import { ref } from 'vue';
import { ensurePush, needsHomeScreen, pushError } from '@/push';

export const locationState = ref('unknown');
export const notificationState = ref('unknown');

export function notificationsSupported() {
    return typeof window !== 'undefined'
        && 'Notification' in window
        && 'serviceWorker' in navigator
        && 'PushManager' in window;
}

export function locationSupported() {
    return typeof navigator !== 'undefined' && !!navigator.geolocation;
}

export async function refreshPermissionState() {
    if (!notificationsSupported()) {
        notificationState.value = 'unsupported';
    } else if (needsHomeScreen()) {
        notificationState.value = 'homescreen';
    } else {
        notificationState.value = Notification.permission;
    }

    if (!locationSupported()) {
        locationState.value = 'unsupported';
        return { location: locationState.value, notification: notificationState.value };
    }

    if (navigator.permissions?.query) {
        try {
            const status = await navigator.permissions.query({ name: 'geolocation' });
            locationState.value = status.state;
            status.onchange = () => {
                locationState.value = status.state;
            };

            return { location: locationState.value, notification: notificationState.value };
        } catch {
            /* Safari and some embeds reject geolocation permission queries. */
        }
    }

    locationState.value = 'prompt';

    return { location: locationState.value, notification: notificationState.value };
}

export function needsPermissionPrompt() {
    const locationMissing = locationState.value !== 'granted' && locationState.value !== 'unsupported';
    const alertsMissing = notificationState.value !== 'granted' && notificationState.value !== 'unsupported';

    return locationMissing || alertsMissing;
}

export function requestLocationAccess() {
    return new Promise((resolve) => {
        if (!locationSupported()) {
            locationState.value = 'unsupported';
            resolve({ ok: false, state: 'unsupported', message: 'This browser cannot share your location.' });
            return;
        }

        const finish = (error) => {
            const denied = error?.code === 1 || locationState.value === 'denied';
            locationState.value = denied ? 'denied' : 'prompt';
            resolve({
                ok: false,
                state: locationState.value,
                message: denied
                    ? 'Location is blocked. Allow it for SmackChat in the browser, and also in macOS System Settings → Privacy & Security → Location Services.'
                    : error?.code === 3
                        ? 'Location timed out. Move closer to a window, then try again.'
                        : 'Could not read your location. Check Location Services for your browser, then try again.',
            });
        };

        navigator.geolocation.getCurrentPosition(
            () => {
                locationState.value = 'granted';
                resolve({ ok: true, state: 'granted' });
            },
            (error) => {
                if (error?.code === 1) {
                    finish(error);
                    return;
                }

                navigator.geolocation.getCurrentPosition(
                    () => {
                        locationState.value = 'granted';
                        resolve({ ok: true, state: 'granted' });
                    },
                    finish,
                    { enableHighAccuracy: false, timeout: 20000, maximumAge: 60000 },
                );
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 },
        );
    });
}

export async function requestNotificationAccess() {
    if (needsHomeScreen()) {
        notificationState.value = 'homescreen';
        return {
            ok: false,
            state: 'homescreen',
            message: 'Add SmackChat to your Home Screen, open that icon, then allow alerts.',
        };
    }

    if (!notificationsSupported()) {
        notificationState.value = 'unsupported';
        return { ok: false, state: 'unsupported', message: 'This phone cannot receive SmackChat alerts.' };
    }

    const ready = await ensurePush({ prompt: true });
    notificationState.value = Notification.permission;
    if (!ready) {
        return {
            ok: false,
            state: notificationState.value,
            message: pushError.value || 'Allow notifications for SmackChat.',
        };
    }

    return { ok: true, state: 'granted' };
}

export async function requestAppPermissions() {
    const location = await requestLocationAccess();
    const notification = await requestNotificationAccess();

    return { location, notification };
}
