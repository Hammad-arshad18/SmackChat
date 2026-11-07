<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import { errorText } from '@/api';
import ShiftWidget from '@/components/ShiftWidget.vue';
import { useAuthStore } from '@/stores/auth';
import { useOfficeStore } from '@/stores/office';
import { formatClock, formatDay, sameDay } from '@/time';

const emit = defineEmits(['channel', 'dm', 'workspaces', 'command']);
const auth = useAuthStore();
const office = useOfficeStore();
const route = useRoute();
const router = useRouter();
const toast = useToast();
const query = ref('');
const userMenu = ref(null);

const initials = computed(() => (office.workspace?.name || 'S').slice(0, 1).toUpperCase());
const roleLabel = computed(() => (office.workspace?.role || 'member').replace(/^\w/, (letter) => letter.toUpperCase()));

const links = computed(() => [
    { label: 'Today', icon: 'pi pi-home', to: `/w/${office.slug}`, exact: true },
    { label: 'Attendance', icon: 'pi pi-stopwatch', to: `/w/${office.slug}/attendance`, badge: office.today?.status === 'open' ? 'Live' : null },
    { label: 'Schedule', icon: 'pi pi-calendar', to: `/w/${office.slug}/hours` },
    { label: 'People', icon: 'pi pi-users', to: `/w/${office.slug}/members` },
    { label: 'Plans', icon: 'pi pi-sparkles', to: `/w/${office.slug}/billing` },
    { label: 'Settings', icon: 'pi pi-sliders-h', to: `/w/${office.slug}/settings` },
]);

const userItems = computed(() => [
    { label: 'Profile & settings', icon: 'pi pi-user', command: () => router.push(`/w/${office.slug}/settings`) },
    { label: 'Open command palette', icon: 'pi pi-search', command: () => emit('command') },
    { separator: true },
    { label: 'Log out', icon: 'pi pi-sign-out', command: logout },
]);

function isActive(link) {
    if (link.exact) {
        return route.path === link.to;
    }

    return route.path.startsWith(link.to);
}

function matches(channel) {
    const needle = query.value.trim().toLowerCase();
    if (!needle) {
        return true;
    }

    return `${channel.label} ${channel.excerpt || ''} ${channel.topic || ''}`.toLowerCase().includes(needle);
}

const rooms = computed(() => office.rooms.filter(matches));
const directs = computed(() => office.directs.filter(matches));
const showTimesheet = computed(() => office.timesheet && matches(office.timesheet));
const totalUnread = computed(() => office.totalUnread);

function icon(channel) {
    if (channel.type === 'attendance') return 'pi pi-clipboard';
    if (channel.type === 'private') return 'pi pi-lock';
    return 'pi pi-hashtag';
}

function when(iso) {
    if (!iso) return '';
    return sameDay(iso, new Date().toISOString()) ? formatClock(iso) : formatDay(iso);
}

function online(channel) {
    const id = channel.people?.[0]?.id;
    return office.members.some((person) => person.id === id && person.online);
}

function openChannel(slug) {
    router.push(`/w/${office.slug}/c/${slug}`);
}

async function markAllRead() {
    try {
        await office.markAllRead();
        toast.add({ severity: 'success', summary: 'Inbox zero', detail: 'Every conversation is marked as read.', life: 2200 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Read state', detail: errorText(error), life: 3000 });
    }
}

async function logout() {
    await auth.logout().catch(() => {});
    office.reset();
    router.push('/login');
}

function toggleUserMenu(event) {
    userMenu.value.toggle(event);
}
</script>

<template>
    <div class="nav-panel">
        <div class="brand-row">
            <RouterLink :to="`/w/${office.slug}`" class="brand">
                <span class="brand-mark"><i class="pi pi-comment" /></span>
                <span class="brand-word">smack<span class="brand-dot">.</span></span>
            </RouterLink>
            <button type="button" class="icon-btn ghost" aria-label="Search" @click="emit('command')">
                <i class="pi pi-search" />
            </button>
        </div>

        <button type="button" class="ws-switcher" @click="emit('workspaces')">
            <Avatar :label="initials" shape="circle" class="ws-avatar" />
            <span class="ws-copy">
                <strong>{{ office.workspace.name }}</strong>
                <small>{{ roleLabel }} · {{ office.members.length }} people</small>
            </span>
            <i class="pi pi-chevron-down ws-chevron" />
        </button>

        <button type="button" class="cmd-trigger" @click="emit('command')">
            <i class="pi pi-search" />
            <span>Search or jump to…</span>
            <kbd>⌘K</kbd>
        </button>

        <nav class="side-nav">
            <RouterLink
                v-for="link in links"
                :key="link.to"
                :to="link.to"
                class="side-link"
                :class="{ 'is-active': isActive(link) }"
            >
                <i :class="link.icon" />
                <span>{{ link.label }}</span>
                <span v-if="link.badge" class="live-pill">{{ link.badge }}</span>
            </RouterLink>
        </nav>

        <div class="nav-scroll">
            <div class="nav-section">
                <div class="nav-section-head">
                    <span>Channels</span>
                    <div class="nav-section-tools">
                        <button
                            v-if="totalUnread"
                            type="button"
                            class="icon-btn ghost tiny"
                            aria-label="Mark all conversations read"
                            v-tooltip.top="'Mark all read'"
                            @click="markAllRead"
                        >
                            <i class="pi pi-check-square" />
                        </button>
                        <button type="button" class="icon-btn ghost tiny" aria-label="New channel" @click="emit('channel')">
                            <i class="pi pi-plus" />
                        </button>
                    </div>
                </div>

                <div class="nav-filter">
                    <i class="pi pi-search" />
                    <input v-model="query" type="search" placeholder="Filter conversations" aria-label="Filter conversations">
                    <button v-if="query" type="button" class="clear-filter" aria-label="Clear filter" @click="query = ''">
                        <i class="pi pi-times" />
                    </button>
                </div>

                <button
                    v-for="channel in rooms"
                    :key="channel.id"
                    type="button"
                    class="conv-link"
                    :class="{ 'is-active': route.params.channel === channel.slug }"
                    @click="openChannel(channel.slug)"
                >
                    <span class="conv-icon" :class="`type-${channel.type}`"><i :class="icon(channel)" /></span>
                    <span class="conv-copy">
                        <span class="conv-top">
                            <strong>{{ channel.label }}</strong>
                            <time>{{ when(channel.last_at) }}</time>
                        </span>
                        <span class="conv-bottom">
                            <span>{{ channel.excerpt || channel.topic || 'No messages yet' }}</span>
                            <span v-if="channel.unread" class="unread-count">{{ channel.unread > 99 ? '99+' : channel.unread }}</span>
                        </span>
                    </span>
                </button>

                <button
                    v-if="showTimesheet"
                    type="button"
                    class="conv-link"
                    :class="{ 'is-active': route.params.channel === office.timesheet.slug }"
                    @click="openChannel(office.timesheet.slug)"
                >
                    <span class="conv-icon type-attendance"><i class="pi pi-clipboard" /></span>
                    <span class="conv-copy">
                        <span class="conv-top">
                            <strong>Timesheets</strong>
                            <time>{{ when(office.timesheet.last_at) }}</time>
                        </span>
                        <span class="conv-bottom">
                            <span>{{ office.timesheet.excerpt || 'Daily hours land here' }}</span>
                            <span v-if="office.timesheet.unread" class="unread-count">{{ office.timesheet.unread }}</span>
                        </span>
                    </span>
                </button>
            </div>

            <div class="nav-section">
                <div class="nav-section-head">
                    <span>Direct messages</span>
                    <button type="button" class="icon-btn ghost tiny" aria-label="New direct message" @click="emit('dm')">
                        <i class="pi pi-plus" />
                    </button>
                </div>

                <p v-if="!directs.length" class="nav-empty">No conversations yet.</p>
                <button
                    v-for="channel in directs"
                    :key="channel.id"
                    type="button"
                    class="conv-link dm"
                    :class="{ 'is-active': route.params.channel === channel.slug }"
                    @click="openChannel(channel.slug)"
                >
                    <span class="presence-avatar" :class="{ online: online(channel) }">
                        <Avatar
                            :label="(channel.people[0]?.name || '?').slice(0, 1)"
                            shape="circle"
                            :style="{ background: channel.people[0]?.avatar_color || '#0e7c66', color: '#fff' }"
                        />
                    </span>
                    <span class="conv-copy">
                        <span class="conv-top">
                            <strong>{{ channel.label }}</strong>
                            <time>{{ when(channel.last_at) }}</time>
                        </span>
                        <span class="conv-bottom">
                            <span>{{ channel.excerpt || 'Start the conversation' }}</span>
                            <span v-if="channel.unread" class="unread-count">{{ channel.unread }}</span>
                        </span>
                    </span>
                </button>
            </div>
        </div>

        <ShiftWidget class="nav-shift" />

        <div class="user-chip">
            <span class="presence-avatar online">
                <Avatar :label="auth.user.name.slice(0, 1)" shape="circle" :style="{ background: auth.user.avatar_color, color: '#fff' }" />
            </span>
            <span class="user-copy">
                <strong>{{ auth.user.name }}</strong>
                <small>{{ auth.user.status_text || auth.user.title || 'Available' }}</small>
            </span>
            <button type="button" class="icon-btn ghost" aria-label="Your account" @click="toggleUserMenu">
                <i class="pi pi-ellipsis-h" />
            </button>
            <Menu ref="userMenu" :model="userItems" popup />
        </div>
    </div>
</template>
