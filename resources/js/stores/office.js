import { defineStore } from 'pinia';
import api from '@/api';
import { ensureDevice, revealChannels, revealMessage } from '@/e2e';
import { bindOffice, realtimeConnected, stopRealtime } from '@/realtime';
import { useAuthStore } from '@/stores/auth';

let channelRefreshTimer = null;
let shiftPoll = null;

function shiftVersion(day) {
    const events = day?.events || [];
    const last = events.reduce((max, event) => Math.max(max, Date.parse(event.happened_at) || 0), 0);

    return last * 1000 + events.length;
}

function watchShift(store) {
    clearTimeout(shiftPoll);

    const tick = async () => {
        if (!store.slug) {
            return;
        }

        try {
            await store.refreshToday();
        } catch {
            /* the next pass retries */
        }

        if (!store.slug) {
            return;
        }

        shiftPoll = setTimeout(tick, shiftWait(store));
    };

    shiftPoll = setTimeout(tick, shiftWait(store));
}

function shiftWait(store) {
    if (realtimeConnected()) {
        return 20000;
    }

    const status = store.today?.status;

    return status === 'open' || status === 'on_break' ? 5000 : 8000;
}

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
            const userId = useAuthStore().user?.id;
            if (userId) {
                try {
                    await ensureDevice(userId);
                    await revealChannels(this);
                } catch {
                    /* channel list still loads; sending waits until a device key is ready */
                }
            }
            bindOffice(
                this.workspace.id,
                this.channels,
                userId,
                (payload) => this.ingest(payload),
                (payload) => this.applyShift(payload?.day),
            );
            watchShift(this);
        },
        applyShift(day) {
            if (!day || (this.today && shiftVersion(day) < shiftVersion(this.today))) {
                return;
            }

            this.today = day;

            const floorIndex = this.floorRows.findIndex((row) => row.user_id === day.user_id && row.work_date === day.work_date);
            if (floorIndex !== -1) {
                this.floorRows.splice(floorIndex, 1, { ...this.floorRows[floorIndex], ...day });
            }

            const monthIndex = this.monthRows.findIndex((row) => row.id === day.id);
            if (monthIndex !== -1) {
                this.monthRows.splice(monthIndex, 1, { ...this.monthRows[monthIndex], ...day });
            }
        },
        async refreshToday() {
            if (!this.slug) {
                return;
            }

            const { data } = await api.get(`/workspaces/${this.slug}/attendance/today`);

            if (data.day) {
                this.applyShift(data.day);

                return;
            }

            if (!this.today || this.today.status === 'completed') {
                this.today = null;
            }
        },
        ingest(payload) {
            this.deliver(payload);
        },
        async deliver(payload) {
            let next = payload;
            if (payload?.message?.cipher === 'e2e') {
                next = { ...payload, message: await revealMessage(this, payload.message) };
            }
            window.dispatchEvent(new CustomEvent('smack-message', { detail: next }));
            const message = next?.message;
            const channel = this.channels.find((item) => item.id === message?.channel_id);

            if (next?.event !== 'message.reacted' && channel && message) {
                channel.excerpt = message.excerpt || channel.excerpt;
                channel.last_at = message.created_at || channel.last_at;
            }

            if (next?.event !== 'message.reacted') {
                queueChannelRefresh(this);
            }
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
            await revealChannels(this);
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
            clearTimeout(shiftPoll);
            shiftPoll = null;
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
