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

export function bindOffice(workspaceId, channels, userId, onMessage, onShift, onWorkspaceEvent) {
    stopRealtime();

    ready = api.get('/realtime/token').then(({ data }) => {
        if (!data.enabled || !data.token) {
            return false;
        }

        client = new Ably.Realtime({
            token: data.token,
            clientId: String(data.clientId),
        });

        channels.forEach((channel) => {
            const realtime = client.channels.get(`smackchat:ws:${workspaceId}:ch:${channel.id}`);
            const handler = (message) => onMessage(message.data);
            realtime.subscribe('message', handler);
            subscriptions.push(() => realtime.unsubscribe('message', handler));
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
}

export function subscribeChannel(workspaceId, channelId, onMessage) {
    if (!client) {
        return () => {};
    }

    const realtime = client.channels.get(`smackchat:ws:${workspaceId}:ch:${channelId}`);
    const handler = (message) => onMessage(message.data);
    realtime.subscribe('message', handler);
    const unsubscribe = () => realtime.unsubscribe('message', handler);
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
