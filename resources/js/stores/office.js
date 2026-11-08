import { defineStore } from 'pinia';
import api from '@/api';
import { bindOffice, stopRealtime } from '@/realtime';

let channelRefreshTimer = null;

function queueChannelRefresh(store) {
    clearTimeout(channelRefreshTimer);
    channelRefreshTimer = setTimeout(() => {
        store.refreshChannels();
    }, 1500);
}

export const useOfficeStore = defineStore('office', {
    state: () => ({
        workspace: null,
        channels: [],
        members: [],
        invitations: [],
        today: null,
        floorRows: [],
        monthRows: [],
        loadedMonth: null,
        typing: '',
    }),
    getters: {
        slug: (state) => state.workspace?.slug,
        manages: (state) => ['owner', 'admin'].includes(state.workspace?.role),
        isOwner: (state) => state.workspace?.role === 'owner',
        rooms: (state) => state.channels.filter((channel) => channel.type === 'public' || channel.type === 'private'),
        directs: (state) => state.channels.filter((channel) => channel.type === 'dm'),
        timesheet: (state) => state.channels.find((channel) => channel.type === 'attendance'),
        totalUnread: (state) => state.channels.reduce((total, channel) => total + (channel.unread || 0), 0),
        catchUp: (state) => state.channels
            .filter((channel) => channel.unread > 0)
            .sort((a, b) => (b.last_at || '').localeCompare(a.last_at || '')),
        onlineMembers: (state) => state.members.filter((member) => member.online),
        awayMembers: (state) => state.members.filter((member) => !member.online),
        myFloorRow: (state) => state.floorRows.find((row) => row.user_id === state.today?.user_id) || null,
        activeFloor: (state) => state.floorRows.filter((row) => row.status === 'open' || row.status === 'on_break'),
    },
    actions: {
        async load(slug) {
            const [workspace, channels, members, today, floor] = await Promise.all([
                api.get(`/workspaces/${slug}`),
                api.get(`/workspaces/${slug}/channels`),
                api.get(`/workspaces/${slug}/members`),
                api.get(`/workspaces/${slug}/attendance/today`),
                api.get(`/workspaces/${slug}/attendance/floor`).catch(() => ({ data: { rows: [] } })),
            ]);

            this.workspace = workspace.data.workspace;
            this.channels = channels.data.channels;
            this.members = members.data.members;
            this.invitations = members.data.invitations;
            this.today = today.data.day;
            this.floorRows = floor.data.rows || [];
            bindOffice(this.workspace.id, this.channels, (payload) => this.ingest(payload));
        },
        ingest(payload) {
            window.dispatchEvent(new CustomEvent('smack-message', { detail: payload }));
            const message = payload?.message;
            const channel = this.channels.find((item) => item.id === message?.channel_id);

            if (channel && message) {
                channel.excerpt = message.excerpt || channel.excerpt;
                channel.last_at = message.created_at || channel.last_at;
            }

            queueChannelRefresh(this);
        },
        scheduleRefresh() {
            queueChannelRefresh(this);
        },
        async refreshChannels() {
            if (!this.slug) {
                return;
            }

            const { data } = await api.get(`/workspaces/${this.slug}/channels`);
            this.channels = data.channels;
        },
        async refreshMembers() {
            const { data } = await api.get(`/workspaces/${this.slug}/members`);
            this.members = data.members;
            this.invitations = data.invitations;
        },
        async refreshFloor() {
            if (!this.slug) {
                return;
            }

            const { data } = await api.get(`/workspaces/${this.slug}/attendance/floor`).catch(() => ({ data: { rows: [] } }));
            this.floorRows = data.rows || [];
        },
        async loadMonth(month) {
            if (!this.slug) {
                return [];
            }

            const { data } = await api.get(`/workspaces/${this.slug}/attendance`, { params: { month } });
            this.monthRows = data.rows;
            this.loadedMonth = month;

            return data.rows;
        },
        async markAllRead() {
            await api.post(`/workspaces/${this.slug}/channels/read-all`);
            this.channels = this.channels.map((channel) => ({ ...channel, unread: 0 }));
        },
        async punch(action) {
            const payload = { action };

            if (this.workspace?.location_restricted && (action === 'check_in' || action === 'check_out')) {
                const coords = await this.readLocation();
                Object.assign(payload, coords);
            }

            const { data } = await api.post(`/workspaces/${this.slug}/attendance/punch`, payload);
            this.today = data.day;

            if (data.message) {
                window.dispatchEvent(new CustomEvent('smack-message', { detail: { event: 'message.created', message: data.message } }));
            }

            await Promise.all([this.refreshChannels(), this.refreshFloor()]);

            return data;
        },
        readLocation() {
            return new Promise((resolve, reject) => {
                if (!navigator.geolocation) {
                    reject(new Error('This browser cannot share your location.'));
                    return;
                }

                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        resolve({
                            latitude: position.coords.latitude,
                            longitude: position.coords.longitude,
                            accuracy: position.coords.accuracy,
                        });
                    },
                    () => reject(new Error('Allow location access to check in or out at the approved place.')),
                    { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 },
                );
            });
        },
        reset() {
            stopRealtime();
            this.workspace = null;
            this.channels = [];
            this.members = [];
            this.invitations = [];
            this.today = null;
            this.floorRows = [];
            this.monthRows = [];
            this.loadedMonth = null;
        },
    },
});
