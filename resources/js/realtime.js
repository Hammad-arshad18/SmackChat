import Ably from 'ably';
import api from '@/api';

let client = null;
let ready = Promise.resolve(false);
const subscriptions = [];

export function whenRealtime() {
    return ready;
}

export function realtimeConnected() {
    return client !== null;
}

/**
 * Connect for one workspace. A workspace may bring its own Ably app, so the
 * token request names the workspace; the server picks that office's key.
 */
export function bindOffice(workspace, channels, userId, onMessage, onShift, onWorkspaceEvent, onCallEvent) {
    stopRealtime();

    const workspaceId = typeof workspace === 'object' ? workspace?.id : workspace;
    const slug = typeof workspace === 'object' ? workspace?.slug : null;

    if (!workspaceId) {
        ready = Promise.resolve(false);

        return ready;
    }

    ready = api.get('/realtime/token', { params: slug ? { workspace: slug } : {} }).then(({ data }) => {
        if (!data.enabled || !data.token) {
            return false;
        }

        client = new Ably.Realtime({
            token: data.token,
            clientId: String(data.clientId),
        });

        channels.forEach((channel) => {
            subscribeToChannel(workspaceId, channel.id, onMessage, onCallEvent);
        });

        if (userId) {
            const shift = client.channels.get(`smackchat:ws:${workspaceId}:shift:${userId}`);
            const handler = (message) => onShift(message.data);
            shift.subscribe('shift', handler);
            subscriptions.push(() => shift.unsubscribe('shift', handler));
        }

        if (onWorkspaceEvent) {
            const wsEvents = client.channels.get(`smackchat:ws:${workspaceId}:events`);
            const handler = (message) => onWorkspaceEvent(message.name, message.data);
            wsEvents.subscribe(handler);
            subscriptions.push(() => wsEvents.unsubscribe(handler));
        }

        return true;
    }).catch(() => false);

    return ready;
}

function subscribeToChannel(workspaceId, channelId, onMessage, onCallEvent) {
    const realtime = client.channels.get(`smackchat:ws:${workspaceId}:ch:${channelId}`);
    const handler = (message) => onMessage(message.data);
    realtime.subscribe('message', handler);
    subscriptions.push(() => realtime.unsubscribe('message', handler));

    if (onCallEvent) {
        const callHandler = (message) => onCallEvent(channelId, message.data);
        realtime.subscribe('call', callHandler);
        subscriptions.push(() => realtime.unsubscribe('call', callHandler));
    }
}

export function subscribeChannel(workspaceId, channelId, onMessage, onCallEvent) {
    if (!client) {
        return () => {};
    }

    const realtime = client.channels.get(`smackchat:ws:${workspaceId}:ch:${channelId}`);
    const handler = (message) => onMessage(message.data);
    realtime.subscribe('message', handler);
    const callHandler = onCallEvent ? (message) => onCallEvent(channelId, message.data) : null;

    if (callHandler) {
        realtime.subscribe('call', callHandler);
    }

    const unsubscribe = () => {
        realtime.unsubscribe('message', handler);

        if (callHandler) {
            realtime.unsubscribe('call', callHandler);
        }
    };
    subscriptions.push(unsubscribe);

    return unsubscribe;
}

export function publishTyping(workspaceId, channelId, payload) {
    if (!client) {
        return;
    }

    const channel = client.channels.get(`smackchat:ws:${workspaceId}:typing:${channelId}`);
    channel.publish('typing', payload);
}

export function watchTyping(workspaceId, channelId, handler) {
    if (!client) {
        return () => {};
    }

    const channel = client.channels.get(`smackchat:ws:${workspaceId}:typing:${channelId}`);
    channel.subscribe('typing', (message) => handler(message.data));

    return () => channel.unsubscribe('typing');
}

export function stopRealtime() {
    subscriptions.splice(0).forEach((unsubscribe) => unsubscribe());

    if (client) {
        client.close();
        client = null;
    }
}
