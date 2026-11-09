<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import api, { errorText } from '@/api';
import CommandPalette from '@/components/CommandPalette.vue';
import WorkspaceNav from '@/components/WorkspaceNav.vue';
import { useAuthStore } from '@/stores/auth';
import { useOfficeStore } from '@/stores/office';
import { useUiStore } from '@/stores/ui';
import { ensurePush, isViewingMessage, needsHomeScreen, pushActive, pushError, showAlert } from '@/push';
import { timeAgo } from '@/time';

const route = useRoute();
const router = useRouter();
const toast = useToast();
const auth = useAuthStore();
const office = useOfficeStore();
const ui = useUiStore();
const ready = ref(false);
const navOpen = ref(false);
const channelOpen = ref(false);
const dmOpen = ref(false);
const workspaceOpen = ref(false);
const shortcutsOpen = ref(false);
const busy = ref(false);
const notifications = ref(null);
const quickMenu = ref(null);
const channelForm = ref({ name: '', description: '', private: false, member_ids: [] });
const dmUser = ref(null);
const workspaceForm = ref({ name: '', timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC' });
let heartbeat;

const initials = computed(() => (office.workspace?.name || 'S').slice(0, 1).toUpperCase());
const zones = Intl.supportedValuesOf ? Intl.supportedValuesOf('timeZone') : ['UTC', 'Asia/Dubai', 'Europe/London', 'America/New_York'];
const currentChannel = computed(() => office.channels.find((item) => item.slug === route.params.channel));
const pageTitle = computed(() => {
    if (route.params.channel) {
        return currentChannel.value?.label || 'Conversation';
    }

    if (route.path.endsWith('/attendance')) return 'Attendance';
    if (route.path.endsWith('/hours')) return 'Schedule';
    if (route.path.endsWith('/members')) return 'People';
    if (route.path.endsWith('/billing')) return 'Plans';
    if (route.path.endsWith('/settings')) return 'Settings';

    return 'Today';
});
const pageKicker = computed(() => (route.params.channel ? (currentChannel.value?.type === 'dm' ? 'Direct message' : 'Channel') : 'Workspace'));
const shiftLabel = computed(() => ({
    idle: 'Not checked in',
    open: 'On the clock',
    on_break: 'On a break',
    completed: 'Day complete',
}[office.today?.status || 'idle']));
const shiftState = computed(() => office.today?.status || 'idle');
const quickItems = computed(() => [
    { label: 'New channel', icon: 'pi pi-hashtag', command: () => { channelOpen.value = true; } },
    { label: 'New direct message', icon: 'pi pi-send', command: () => { dmOpen.value = true; } },
    { label: 'Invite someone', icon: 'pi pi-user-plus', command: () => router.push(`/w/${office.slug}/members`) },
    { separator: true },
    { label: 'Workspace settings', icon: 'pi pi-cog', command: () => router.push(`/w/${office.slug}/settings`) },
]);

async function load(slug) {
    ready.value = false;
    try {
        await office.load(slug);
        ready.value = true;
    } catch (error) {
        if (error?.response?.status === 401 || error?.response?.status === 403) {
            auth.user = null;
        }
        toast.add({ severity: 'error', summary: 'Workspace', detail: errorText(error), life: 4000 });
        router.push('/login');
    }
}

function beat() {
    api.post('/heartbeat').catch(() => {});
    if (office.slug) {
        office.refreshMembers().catch(() => {});
        office.refreshChannels().catch(() => {});
    }
}

onMounted(() => {
    load(route.params.slug);
    window.addEventListener('keydown', onGlobalKey);
    window.addEventListener('smack-message', onOfficeMessage);
    window.addEventListener('focus', beat);
    beat();
    heartbeat = setInterval(beat, 30000);
    ensurePush().then((ready) => {
        if (ready) {
            ui.setPreference('desktopNotifications', true);
        }
    });
});

watch(() => route.params.slug, (slug, previous) => {
    if (slug && slug !== previous) {
        load(slug);
    }
});

watch(() => route.fullPath, () => {
    navOpen.value = false;
    notifications.value?.hide?.();
});

watch(() => office.totalUnread, (total) => {
    document.title = total ? `(${total}) SmackChat` : 'SmackChat — your team\'s day, in one place';
});

onBeforeUnmount(() => {
    clearInterval(heartbeat);
    window.removeEventListener('focus', beat);
    window.removeEventListener('keydown', onGlobalKey);
    window.removeEventListener('smack-message', onOfficeMessage);
    document.title = 'SmackChat — your team\'s day, in one place';
    office.reset();
});

const alerted = new Set();

function onOfficeMessage(event) {
    const payload = event.detail;
    const message = payload?.message;
    if (!message || payload?.event !== 'message.created' || message.user?.id === auth.user?.id) {
        return;
    }

    if (alerted.has(message.id)) {
        return;
    }

    alerted.add(message.id);
    if (alerted.size > 200) {
        alerted.delete(alerted.values().next().value);
    }

    const viewing = isViewingMessage(message.channel_id, message.parent_id);
    const phone = needsHomeScreen() || /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);

    if (ui.preferences.messageSounds && !viewing) {
        chime();
    }

    if (viewing && !phone) {
        return;
    }

    if (typeof Notification === 'undefined' || Notification.permission !== 'granted') {
        return;
    }

    const channel = office.channels.find((item) => item.id === message.channel_id);
    const label = channel?.label || office.workspace?.name || 'SmackChat';
    const title = message.parent_id ? `Thread in ${label}` : label;
    const thread = message.parent_ref
        ? `?m=${encodeURIComponent(message.parent_ref)}&reply=${encodeURIComponent(message.ref)}`
        : (message.ref ? `?m=${encodeURIComponent(message.ref)}` : '');

    showAlert(title, {
        body: `${message.user?.name || 'Someone'}: ${message.excerpt || 'New message'}`,
        tag: `smack-m-${message.id}`,
        data: { url: channel ? `/w/${office.slug}/c/${channel.slug}${thread}` : '/app' },
    });
}

function chime() {
    try {
        const Audio = window.AudioContext || window.webkitAudioContext;
        if (!Audio) return;
        const context = new Audio();
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(740, context.currentTime);
        oscillator.frequency.exponentialRampToValueAtTime(980, context.currentTime + 0.12);
        gain.gain.setValueAtTime(0.0001, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.05, context.currentTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.22);
        oscillator.connect(gain);
        gain.connect(context.destination);
        oscillator.start();
        oscillator.stop(context.currentTime + 0.24);
        setTimeout(() => context.close(), 400);
    } catch {
        /* audio is a nice-to-have */
    }
}

function onGlobalKey(event) {
    const typing = ['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable;

    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        ui.openCommand();
        return;
    }

    if (event.key === '?' && !typing) {
        event.preventDefault();
        shortcutsOpen.value = true;
    }
}

async function createChannel() {
    busy.value = true;
    try {
        const { data } = await api.post(`/workspaces/${office.slug}/channels`, channelForm.value);
        channelOpen.value = false;
        channelForm.value = { name: '', description: '', private: false, member_ids: [] };
        await office.refreshChannels();
        router.push(`/w/${office.slug}/c/${data.slug}`);
        toast.add({ severity: 'success', summary: 'Channel ready', life: 2200 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Channel', detail: errorText(error), life: 3500 });
    } finally {
        busy.value = false;
    }
}

async function startDm() {
    if (!dmUser.value) {
        return;
    }

    try {
        const { data } = await api.post(`/workspaces/${office.slug}/dms`, { user_id: dmUser.value });
        dmOpen.value = false;
        dmUser.value = null;
        await office.refreshChannels();
        router.push(`/w/${office.slug}/c/${data.slug}`);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Direct message', detail: errorText(error), life: 3500 });
    }
}

async function createWorkspace() {
    busy.value = true;
    try {
        const { data } = await api.post('/workspaces', workspaceForm.value);
        await auth.fetchMe();
        workspaceOpen.value = false;
        workspaceForm.value = { name: '', timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC' };
        router.push(`/w/${data.slug}`);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Workspace', detail: errorText(error), life: 3500 });
    } finally {
        busy.value = false;
    }
}

async function markAllRead() {
    try {
        await office.markAllRead();
        notifications.value?.hide?.();
        toast.add({ severity: 'success', summary: 'All caught up', life: 2000 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Read state', detail: errorText(error), life: 3000 });
    }
}

const showAlertBanner = computed(() => ready.value && !pushActive.value);
const alertBannerText = computed(() => pushError.value || 'Turn on alerts so messages reach this phone.');

async function enableAlerts() {
    if (needsHomeScreen()) {
        toast.add({
            severity: 'warn',
            summary: 'Open the home screen app',
            detail: 'Add SmackChat to your Home Screen, open that icon, then tap Turn on alerts.',
            life: 6000,
        });
        return;
    }

    const readyPush = await ensurePush({ prompt: true });
    if (!readyPush) {
        toast.add({
            severity: 'warn',
            summary: 'Alerts are off',
            detail: pushError.value || 'Allow notifications for SmackChat.',
            life: 5000,
        });
        return;
    }

    ui.setPreference('desktopNotifications', true);
    const { data } = await api.post('/push/test').catch(() => ({ data: { sent: 0, subscriptions: 0 } }));
    toast.add({
        severity: data?.sent ? 'success' : 'warn',
        summary: data?.sent ? 'Test alert sent' : 'Phone not registered',
        detail: data?.sent
            ? 'You should see “Alerts are on for this phone.”'
            : 'Allow notifications again from the installed SmackChat icon.',
        life: 5000,
    });
}

function openNotifications(event) {
    notifications.value.toggle(event);
    if (!pushActive.value) {
        enableAlerts();
    }
}
</script>

<template>
    <div v-if="ready" class="app-shell">
        <aside class="app-sidebar">
            <WorkspaceNav
                @channel="channelOpen = true"
                @dm="dmOpen = true"
                @workspaces="workspaceOpen = true"
                @command="ui.openCommand()"
            />
        </aside>

        <div class="app-main">
            <button v-if="showAlertBanner" type="button" class="alert-banner" @click="enableAlerts">
                <i class="pi pi-bell" />
                <span>{{ alertBannerText }}</span>
            </button>
            <header class="topbar">
                <div class="topbar-left">
                    <Button icon="pi pi-bars" text rounded class="mobile-menu" aria-label="Open navigation" @click="navOpen = true" />
                    <div class="crumbs">
                        <span>{{ office.workspace.name }}</span>
                        <i class="pi pi-angle-right" />
                        <strong>{{ pageTitle }}</strong>
                        <span class="crumb-type">{{ pageKicker }}</span>
                    </div>
                </div>

                <button type="button" class="top-search" @click="ui.openCommand()">
                    <i class="pi pi-search" />
                    <span>Search messages, people, actions…</span>
                    <kbd>⌘K</kbd>
                </button>

                <div class="top-actions">
                    <RouterLink :to="`/w/${office.slug}/attendance`" class="shift-pill" :class="`state-${shiftState}`">
                        <span class="live-dot" :class="{ pulsing: shiftState === 'open' || shiftState === 'on_break' }" />
                        <span>{{ shiftLabel }}</span>
                        <em v-if="office.today?.work_label && shiftState === 'completed'">{{ office.today.work_label }}</em>
                    </RouterLink>

                    <OverlayBadge v-if="office.totalUnread" :value="office.totalUnread" severity="danger" class="notify-badge">
                        <Button icon="pi pi-bell" text rounded class="notify-btn" aria-label="Notifications" @click="openNotifications" />
                    </OverlayBadge>
                    <Button v-else icon="pi pi-bell" text rounded class="notify-btn" aria-label="Notifications" @click="openNotifications" />

                    <Button
                        :icon="ui.isDark ? 'pi pi-sun' : 'pi pi-moon'"
                        text
                        rounded
                        class="theme-toggle"
                        aria-label="Toggle theme"
                        @click="ui.toggleTheme()"
                    />

                    <Button icon="pi pi-plus" rounded aria-label="Create" @click="quickMenu.toggle($event)" />
                    <Menu ref="quickMenu" :model="quickItems" popup />
                </div>
            </header>

            <main class="app-stage">
                <RouterView />
            </main>
        </div>

        <Drawer v-model:visible="navOpen" position="left" class="nav-drawer">
            <template #container>
                <WorkspaceNav
                    @channel="channelOpen = true; navOpen = false"
                    @dm="dmOpen = true; navOpen = false"
                    @workspaces="workspaceOpen = true; navOpen = false"
                    @command="ui.openCommand(); navOpen = false"
                />
            </template>
        </Drawer>

        <Popover ref="notifications">
            <div class="notif-panel">
                <div class="notif-head">
                    <div>
                        <strong>Catch up</strong>
                        <small>{{ office.totalUnread ? `${office.totalUnread} unread across ${office.catchUp.length} conversation${office.catchUp.length === 1 ? '' : 's'}` : 'You are all caught up' }}</small>
                    </div>
                    <Button v-if="office.totalUnread" label="Mark read" link size="small" @click="markAllRead" />
                </div>
                <div v-if="office.catchUp.length" class="notif-list">
                    <button
                        v-for="channel in office.catchUp"
                        :key="channel.id"
                        type="button"
                        class="notif-row"
                        @click="router.push(`/w/${office.slug}/c/${channel.slug}`)"
                    >
                        <span class="conv-icon" :class="`type-${channel.type}`">
                            <i :class="channel.type === 'dm' ? 'pi pi-user' : channel.type === 'private' ? 'pi pi-lock' : 'pi pi-hashtag'" />
                        </span>
                        <span>
                            <strong>{{ channel.label }}</strong>
                            <small>{{ channel.excerpt || 'New messages' }}</small>
                        </span>
                        <span class="unread-count">{{ channel.unread }}</span>
                    </button>
                </div>
                <div v-else class="notif-empty">
                    <i class="pi pi-check-circle" />
                    <p>Nothing needs you right now. Enjoy the quiet.</p>
                </div>
                <div class="notif-foot">
                    <span>Last update {{ timeAgo(new Date().toISOString()) }}</span>
                    <Button label="Open inbox" link size="small" @click="router.push(`/w/${office.slug}`)" />
                </div>
            </div>
        </Popover>

        <Dialog v-model:visible="channelOpen" modal :style="{ width: '30rem' }" class="smack-dialog">
            <template #header>
                <div class="dialog-title">
                    <span class="dialog-icon"><i class="pi pi-hashtag" /></span>
                    <div><strong>Create a channel</strong><small>A shared room for work that needs an audience.</small></div>
                </div>
            </template>
            <form class="form-stack" @submit.prevent="createChannel">
                <FloatLabel variant="on">
                    <InputText id="channel-name" v-model="channelForm.name" fluid />
                    <label for="channel-name">Channel name</label>
                </FloatLabel>
                <FloatLabel variant="on">
                    <Textarea id="channel-about" v-model="channelForm.description" rows="3" auto-resize fluid />
                    <label for="channel-about">What is it for?</label>
                </FloatLabel>
                <div class="switch-row">
                    <div>
                        <strong>Private channel</strong>
                        <small>Only invited people can find and open it.</small>
                    </div>
                    <ToggleSwitch v-model="channelForm.private" />
                </div>
                <MultiSelect
                    v-if="channelForm.private"
                    v-model="channelForm.member_ids"
                    :options="office.members.filter((person) => person.id !== auth.user.id)"
                    option-label="name"
                    option-value="id"
                    placeholder="Add people"
                    display="chip"
                    filter
                    fluid
                >
                    <template #option="slotProps">
                        <div class="select-person">
                            <PersonAvatar :person="slotProps.option" />
                            <span>{{ slotProps.option.name }}</span>
                        </div>
                    </template>
                </MultiSelect>
                <div class="dialog-actions">
                    <Button label="Cancel" text severity="secondary" type="button" @click="channelOpen = false" />
                    <Button type="submit" label="Create channel" icon="pi pi-arrow-right" :loading="busy" />
                </div>
            </form>
        </Dialog>

        <Dialog v-model:visible="dmOpen" modal :style="{ width: '26rem' }" class="smack-dialog">
            <template #header>
                <div class="dialog-title">
                    <span class="dialog-icon"><i class="pi pi-send" /></span>
                    <div><strong>Start a direct message</strong><small>Only the two of you can read it.</small></div>
                </div>
            </template>
            <form class="form-stack" @submit.prevent="startDm">
                <Select
                    v-model="dmUser"
                    :options="office.members.filter((person) => person.id !== auth.user.id)"
                    option-label="name"
                    option-value="id"
                    placeholder="Choose a teammate"
                    filter
                    fluid
                >
                    <template #option="slotProps">
                        <div class="select-person">
                            <span class="presence-avatar" :class="{ online: slotProps.option.online }">
                                <PersonAvatar :person="slotProps.option" />
                            </span>
                            <span>
                                <strong>{{ slotProps.option.name }}</strong>
                                <small>{{ slotProps.option.title || slotProps.option.email }}</small>
                            </span>
                        </div>
                    </template>
                </Select>
                <div class="dialog-actions">
                    <Button label="Cancel" text severity="secondary" type="button" @click="dmOpen = false" />
                    <Button type="submit" label="Open conversation" icon="pi pi-arrow-right" :disabled="!dmUser" />
                </div>
            </form>
        </Dialog>

        <Dialog v-model:visible="workspaceOpen" modal :style="{ width: '32rem' }" class="smack-dialog">
            <template #header>
                <div class="dialog-title">
                    <span class="dialog-icon"><i class="pi pi-box" /></span>
                    <div><strong>Your workspaces</strong><small>Jump into another company or open a new one.</small></div>
                </div>
            </template>
            <div class="workspace-list">
                <button
                    v-for="item in auth.user.workspaces"
                    :key="item.slug"
                    type="button"
                    class="workspace-option"
                    :class="{ current: item.slug === office.slug }"
                    @click="workspaceOpen = false; router.push(`/w/${item.slug}`)"
                >
                    <Avatar
                        :image="item.logo_url || undefined"
                        :label="!item.logo_url ? item.name.slice(0, 1) : undefined"
                        shape="circle"
                        :style="{ background: item.slug === office.slug ? '#0e7c66' : '#e4dfd2', color: item.slug === office.slug ? '#fff' : '#25231f' }"
                    />
                    <span>
                        <strong>{{ item.name }}</strong>
                        <small>{{ item.role }}</small>
                    </span>
                    <i v-if="item.slug === office.slug" class="pi pi-check" />
                    <i v-else class="pi pi-arrow-right" />
                </button>
            </div>
            <Divider />
            <form class="form-stack" @submit.prevent="createWorkspace">
                <p class="form-label">Open a new workspace</p>
                <FloatLabel variant="on">
                    <InputText id="workspace-name" v-model="workspaceForm.name" fluid />
                    <label for="workspace-name">Company or team name</label>
                </FloatLabel>
                <Select v-model="workspaceForm.timezone" :options="zones" filter fluid />
                <Button label="Create workspace" icon="pi pi-plus" :loading="busy" />
            </form>
        </Dialog>

        <Dialog v-model:visible="shortcutsOpen" modal :style="{ width: '34rem' }" class="smack-dialog">
            <template #header>
                <div class="dialog-title">
                    <span class="dialog-icon"><i class="pi pi-key" /></span>
                    <div><strong>Keyboard shortcuts</strong><small>Move around without reaching for the mouse.</small></div>
                </div>
            </template>
            <div class="shortcut-grid">
                <div><span>Command palette</span><kbd>⌘</kbd><kbd>K</kbd></div>
                <div><span>Shortcuts</span><kbd>?</kbd></div>
                <div><span>Send message</span><kbd>↵</kbd></div>
                <div><span>New line</span><kbd>⇧</kbd><kbd>↵</kbd></div>
                <div><span>Bold</span><kbd>⌘</kbd><kbd>B</kbd></div>
                <div><span>Italic</span><kbd>⌘</kbd><kbd>I</kbd></div>
                <div><span>Open channel</span><kbd>⌘</kbd><kbd>K</kbd><kbd>↵</kbd></div>
                <div><span>Attendance</span><kbd>⌘</kbd><kbd>K</kbd><kbd>⇧</kbd><kbd>A</kbd></div>
            </div>
        </Dialog>

        <CommandPalette @channel="channelOpen = true" @dm="dmOpen = true" @workspaces="workspaceOpen = true" />
    </div>

    <div v-else class="boot-screen">
        <div class="boot-mark"><i class="pi pi-comment" /></div>
        <strong>Opening {{ route.params.slug || 'your workspace' }}</strong>
        <span>Warming up the floor clock…</span>
    </div>
</template>
