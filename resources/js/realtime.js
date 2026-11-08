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

export function bindOffice(workspaceId, channels, onMessage) {
    stopRealtime();

    ready = api.get('/realtime/token').then(({ data }) => {
        if (!data.enabled || !data.token) {
            return;
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

        return true;
    }).catch(() => false);
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
