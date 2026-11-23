<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from '@/toast';
import {
    ai,
    aiEngineLabel,
    initAiCapabilities,
    prepareModel,
    redownloadLocalModel,
    removeDownloadedLocalModel,
} from '@/ai';
import api, { errorText } from '@/api';
import { disablePush, ensurePush } from '@/push';
import { useAuthStore } from '@/stores/auth';
import { useOfficeStore } from '@/stores/office';
import { useUiStore } from '@/stores/ui';
import { minutesLabel, timeAgo } from '@/time';

const auth = useAuthStore();
const office = useOfficeStore();
const ui = useUiStore();
const router = useRouter();
const toast = useToast();
const confirm = useConfirm();

const busy = ref(false);
const avatarBusy = ref(false);
const avatarInput = ref(null);
const logoBusy = ref(false);
const logoInput = ref(null);
const aiBusy = ref(false);
const deleteOpen = ref(false);
const deletePassword = ref('');
const deleting = ref(false);
const zones = Intl.supportedValuesOf ? Intl.supportedValuesOf('timeZone') : ['UTC', 'Asia/Dubai', 'Europe/London', 'America/New_York'];
const form = reactive({
    name: '',
    title: '',
    status_emoji: '',
    status_text: '',
    timezone: 'UTC',
});

const themeOptions = [
    { label: 'Light', value: 'light', icon: 'pi pi-sun' },
    { label: 'Dark', value: 'dark', icon: 'pi pi-moon' },
    { label: 'System', value: 'system', icon: 'pi pi-desktop' },
];
const densityOptions = [
    { label: 'Comfortable', value: 'comfortable' },
    { label: 'Compact', value: 'compact' },
];
const statusPresets = [
    { emoji: '🎯', text: 'Heads down' },
    { emoji: '📅', text: 'In a meeting' },
    { emoji: '☕', text: 'Coffee break' },
    { emoji: '🌴', text: 'On leave' },
    { emoji: '🧠', text: 'Deep work' },
    { emoji: '💬', text: 'Available' },
];

const locale = Intl.DateTimeFormat().resolvedOptions().locale;
const roleLabel = computed(() => (office.workspace?.role || 'member').replace(/^\w/, (letter) => letter.toUpperCase()));
const aiEngineName = computed(() => aiEngineLabel());
const aiStatusLabel = computed(() => {
    if (ai.engine === 'unavailable') {
        return 'Not supported here';
    }

    if (ai.modelState === 'ready') {
        return 'Downloaded & Ready';
    }

    if (ai.modelState === 'downloading') {
        return `Downloading ${ai.downloadPercent}%`;
    }

    return 'Not Downloaded';
});
const aiCanDownload = computed(() => ai.engine === 'transformers-local' && ai.modelState === 'not-downloaded');
const aiCanDelete = computed(() => ai.engine === 'transformers-local' && (ai.modelState === 'ready' || ai.hasLocalCache));
const aiCanRedownload = computed(() => ai.engine === 'transformers-local' && ai.modelState === 'ready');
const ablyKey = ref('');
const ablyBusy = ref(false);
const ablyTesting = ref(false);
const realtimeMode = computed(() => office.workspace?.realtime?.mode || 'polling');
const realtimeLabel = computed(() => ({
    workspace: 'Your Ably app',
    server: 'Server Ably key',
    polling: 'Polling (no Ably key)',
}[realtimeMode.value] || 'Polling (no Ably key)'));
const realtimeTagLabel = computed(() => ({
    workspace: 'Own key',
    server: 'Server key',
    polling: 'Polling',
}[realtimeMode.value] || 'Polling'));
const realtimeTagSeverity = computed(() => ({
    workspace: 'success',
    server: 'secondary',
    polling: 'warn',
}[realtimeMode.value] || 'warn'));

onMounted(async () => {
    try {
        await initAiCapabilities();
    } catch {
        /* the section just reports "not supported" */
    }
});

async function downloadAiModel() {
    if (aiBusy.value) {
        return;
    }

    aiBusy.value = true;
    const downloading = ai.modelState !== 'ready';
    const stop = downloading
        ? toast.followPercent('ai-model', 'Downloading model', () => ai.downloadPercent)
        : null;

    try {
        await prepareModel();
        stop?.();
        if (downloading) {
            toast.finish('ai-model', {
                summary: 'Offline AI ready',
                detail: 'The model is cached on this device. Future sessions reuse it.',
                life: 3200,
            });
        } else {
            toast.add({
                severity: 'success',
                summary: 'Offline AI ready',
                detail: 'The model is cached on this device. Future sessions reuse it.',
                life: 3200,
            });
        }
    } catch (error) {
        stop?.();
        const failed = {
            severity: 'warn',
            summary: 'Offline AI',
            detail: errorText(error, 'Could not start the offline AI model on this device.'),
            life: 4600,
        };
        if (downloading) {
            toast.finish('ai-model', failed);
        } else {
            toast.add(failed);
        }
    } finally {
        aiBusy.value = false;
    }
}

function deleteAiModel() {
    confirm.require({
        message: 'Delete the downloaded offline AI model from this device? Your messages are untouched, and you can download it again later.',
        header: 'Delete downloaded model',
        icon: 'pi pi-trash',
        rejectProps: { label: 'Keep it', severity: 'secondary', text: true },
        acceptProps: { label: 'Delete', severity: 'danger' },
        accept: async () => {
            await removeDownloadedLocalModel();
            toast.add({ severity: 'success', summary: 'Model deleted', detail: 'Disk space is free again.', life: 2600 });
        },
    });
}

function redownloadAiModel() {
    confirm.require({
        message: 'Delete the downloaded model and download the fastest one for this browser? The model downloads again, once.',
        header: 'Re-download offline AI',
        icon: 'pi pi-refresh',
        rejectProps: { label: 'Cancel', severity: 'secondary', text: true },
        acceptProps: { label: 'Re-download', severity: 'primary' },
        accept: async () => {
            aiBusy.value = true;
            const stop = toast.followPercent('ai-model', 'Downloading model', () => ai.downloadPercent);

            try {
                await redownloadLocalModel();
                stop();
                toast.finish('ai-model', {
                    summary: 'Offline AI ready',
                    detail: 'The fastest model for this browser is cached on this device.',
                    life: 3200,
                });
            } catch (error) {
                stop();
                toast.finish('ai-model', {
                    severity: 'warn',
                    summary: 'Offline AI',
                    detail: errorText(error, 'Could not start the offline AI model on this device.'),
                    life: 4600,
                });
            } finally {
                aiBusy.value = false;
            }
        },
    });
}

function syncForm() {
    form.name = auth.user.name;
    form.title = auth.user.title || '';
    form.status_emoji = auth.user.status_emoji || '';
    form.status_text = auth.user.status_text || '';
    form.timezone = auth.user.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
}

watch(() => auth.user, syncForm, { immediate: true });

async function saveAblyKey() {
    if (ablyBusy.value || !ablyKey.value.trim()) {
        return;
    }

    ablyBusy.value = true;

    try {
        const { data } = await api.put(`/workspaces/${office.slug}/realtime`, {
            ably_key: ablyKey.value.trim(),
        });
        office.workspace = { ...office.workspace, realtime: data.realtime };
        ablyKey.value = '';
        office.reconnectRealtime();
        toast.add({
            severity: 'success',
            summary: 'Realtime key saved',
            detail: 'This workspace now uses its own Ably app. Teammates pick it up when they reload.',
            life: 4600,
        });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Ably key', detail: errorText(error, 'Could not save that key.'), life: 4200 });
    } finally {
        ablyBusy.value = false;
    }
}

function removeAblyKey() {
    confirm.require({
        message: 'Go back to the server Ably key? Live messages keep working, but the workspace stops using its own Ably app.',
        header: 'Remove workspace Ably key',
        icon: 'pi pi-times',
        rejectProps: { label: 'Keep it', severity: 'secondary', text: true },
        acceptProps: { label: 'Remove', severity: 'danger' },
        accept: async () => {
            try {
                const { data } = await api.delete(`/workspaces/${office.slug}/realtime`);
                office.workspace = { ...office.workspace, realtime: data.realtime };
                office.reconnectRealtime();
                toast.add({ severity: 'success', summary: 'Key removed', life: 2600 });
            } catch (error) {
                toast.add({ severity: 'error', summary: 'Ably key', detail: errorText(error), life: 4000 });
            }
        },
    });
}

async function testRealtime() {
    if (ablyTesting.value) {
        return;
    }

    ablyTesting.value = true;

    try {
        const { data } = await api.get('/realtime/token', { params: { workspace: office.slug } });

        if (data.enabled && data.token) {
            toast.add({
                severity: 'success',
                summary: 'Realtime is live',
                detail: data.source === 'workspace' ? 'Ably accepted the workspace key.' : 'Ably accepted the server key.',
                life: 3600,
            });
        } else {
            toast.add({
                severity: 'warn',
                summary: 'No live connection',
                detail: 'Ably did not return a token. Chat still works by polling. Check the key format appId.keyId:secret.',
                life: 5200,
            });
        }
    } catch (error) {
        toast.add({ severity: 'warn', summary: 'Realtime test', detail: errorText(error), life: 4200 });
    } finally {
        ablyTesting.value = false;
    }
}

async function saveProfile() {
    busy.value = true;
    try {
        await auth.updateProfile({ ...form });
        await office.refreshMembers();
        toast.add({ severity: 'success', summary: 'Profile saved', detail: 'Your teammates will see the update.', life: 2500 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Profile', detail: errorText(error), life: 3500 });
    } finally {
        busy.value = false;
    }
}

async function uploadAvatar(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) {
        return;
    }

    avatarBusy.value = true;
    try {
        await auth.updateAvatar(file);
        await office.refreshMembers();
        toast.add({
            severity: 'success',
            summary: 'Photo updated',
            detail: 'Your profile picture is saved.',
            person: auth.user,
            life: 2800,
        });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Profile photo', detail: errorText(error), life: 3500 });
    } finally {
        avatarBusy.value = false;
    }
}

async function removeAvatar() {
    avatarBusy.value = true;
    try {
        await auth.removeAvatar();
        await office.refreshMembers();
        toast.add({ severity: 'success', summary: 'Photo removed', life: 2200 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Profile photo', detail: errorText(error), life: 3500 });
    } finally {
        avatarBusy.value = false;
    }
}

async function uploadLogo(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) {
        return;
    }

    logoBusy.value = true;
    try {
        await office.updateLogo(file);
        await auth.fetchMe();
        toast.add({
            severity: 'success',
            summary: 'Logo updated',
            detail: 'Company logo has been saved.',
            person: { name: office.workspace?.name, avatar_url: office.workspace?.logo_url },
            life: 2800,
        });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Company logo', detail: errorText(error), life: 3500 });
    } finally {
        logoBusy.value = false;
    }
}

async function removeLogo() {
    logoBusy.value = true;
    try {
        await office.removeLogo();
        await auth.fetchMe();
        toast.add({ severity: 'success', summary: 'Logo removed', life: 2200 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Company logo', detail: errorText(error), life: 3500 });
    } finally {
        logoBusy.value = false;
    }
}

async function applyStatus(preset) {
    form.status_emoji = preset.emoji;
    form.status_text = preset.text;
    await saveProfile();
}

async function setNotifications(enabled) {
    if (!enabled) {
        ui.setPreference('desktopNotifications', false);
        await disablePush();
        return;
    }

    if (!('Notification' in window) || !('serviceWorker' in navigator)) {
        toast.add({ severity: 'warn', summary: 'Not supported', detail: 'This browser cannot show notifications.', life: 3000 });
        return;
    }

    const ready = await ensurePush({ prompt: true });
    ui.setPreference('desktopNotifications', ready);

    if (!ready) {
        toast.add({
            severity: 'warn',
            summary: 'Notifications are off',
            detail: 'Allow notifications for SmackChat. Installed apps also need that permission to alert you in the background.',
            life: 4200,
        });
    }
}

function leaveWorkspace() {
    confirm.require({
        message: 'Leave this workspace? You can only rejoin with an invite.',
        header: 'Leave workspace',
        icon: 'pi pi-sign-out',
        rejectProps: { label: 'Stay', severity: 'secondary', text: true },
        acceptProps: { label: 'Leave', severity: 'danger' },
        accept: async () => {
            try {
                await api.delete(`/workspaces/${office.slug}/members/${auth.user.id}`);
                await auth.fetchMe();
                router.push('/app');
            } catch (error) {
                toast.add({ severity: 'error', summary: 'Leave workspace', detail: errorText(error), life: 3500 });
            }
        },
    });
}

async function downloadMyData() {
    try {
        const response = await api.get('/me/export', { responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'smackchat-export.json';
        link.rel = 'noreferrer';
        link.click();
        URL.revokeObjectURL(url);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Export failed', detail: errorText(error, 'Could not build your export.'), life: 3500 });
    }
}

async function deleteAccount() {
    if (!deletePassword.value) {
        return;
    }

    deleting.value = true;
    try {
        await api.delete('/me', { data: { password: deletePassword.value } });
        deleteOpen.value = false;
        deletePassword.value = '';
        try {
            await auth.logout();
        } catch {
            /* the session is already gone */
        }
        auth.user = null;
        router.push('/login');
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Not deleted', detail: errorText(error, 'Could not delete the account.'), life: 4000 });
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <div class="page settings-page">
        <header class="page-head">
            <div>
                <p class="eyebrow">Settings <span class="dot-sep">·</span> Your account</p>
                <h1 class="display-title">Make SmackChat yours</h1>
                <p class="lede">Tune how you appear, how the app feels, and the workspace you call home.</p>
            </div>
            <div class="page-tools">
                <Button label="Save profile" icon="pi pi-check" :loading="busy" @click="saveProfile" />
            </div>
        </header>

        <div class="settings-grid">
            <section class="panel profile-panel">
                <header class="card-head">
                    <div><p class="card-kicker">Profile</p><h2>How you show up</h2></div>
                    <Tag :value="roleLabel" severity="secondary" />
                </header>

                <div class="profile-hero">
                    <div class="avatar-editor">
                        <span class="presence-avatar online">
                            <PersonAvatar :person="auth.user" :name="auth.user.name" size="xlarge" />
                        </span>
                        <div class="avatar-actions">
                            <input ref="avatarInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden @change="uploadAvatar">
                            <Button label="Upload photo" icon="pi pi-image" size="small" severity="secondary" :loading="avatarBusy" @click="avatarInput?.click()" />
                            <Button v-if="auth.user.avatar_url" label="Remove" icon="pi pi-times" size="small" text :disabled="avatarBusy" @click="removeAvatar" />
                        </div>
                    </div>
                    <div>
                        <h2>{{ form.name || auth.user.name }}</h2>
                        <p>{{ form.status_emoji }} {{ form.status_text || form.title || 'Available' }}</p>
                        <small>{{ auth.user.email }} · {{ auth.user.timezone }}</small>
                    </div>
                </div>

                <div class="form-grid two">
                    <div class="field">
                        <label>Display name</label>
                        <InputText v-model="form.name" fluid />
                    </div>
                    <div class="field">
                        <label>Role title</label>
                        <InputText v-model="form.title" placeholder="e.g. Design lead" fluid />
                    </div>
                </div>

                <div class="form-grid two">
                    <div class="field small-field">
                        <label>Status emoji</label>
                        <InputText v-model="form.status_emoji" maxlength="4" placeholder="🎯" />
                    </div>
                    <div class="field">
                        <label>Status message</label>
                        <InputText v-model="form.status_text" placeholder="What are you up to?" fluid />
                    </div>
                </div>

                <div class="field">
                    <label>Timezone</label>
                    <Select v-model="form.timezone" :options="zones" filter fluid />
                </div>

                <div class="preset-row">
                    <span class="form-label">Quick status</span>
                    <div class="chip-row">
                        <button
                            v-for="preset in statusPresets"
                            :key="preset.text"
                            type="button"
                            class="status-chip"
                            :class="{ active: form.status_text === preset.text && form.status_emoji === preset.emoji }"
                            @click="applyStatus(preset)"
                        >
                            <span>{{ preset.emoji }}</span> {{ preset.text }}
                        </button>
                    </div>
                </div>
            </section>

            <section class="panel appearance-panel">
                <header class="card-head">
                    <div><p class="card-kicker">Appearance</p><h2>How the app feels</h2></div>
                    <Tag :value="ui.isDark ? 'Dark' : 'Light'" :severity="ui.isDark ? 'contrast' : 'secondary'" />
                </header>

                <div class="field">
                    <label>Theme</label>
                    <div class="theme-options">
                        <button
                            v-for="option in themeOptions"
                            :key="option.value"
                            type="button"
                            class="theme-card"
                            :class="{ active: ui.theme === option.value, dark: option.value === 'dark' }"
                            @click="ui.setTheme(option.value)"
                        >
                            <span class="theme-preview" :class="`preview-${option.value}`">
                                <i class="preview-side" />
                                <i class="preview-card" />
                                <i class="preview-card short" />
                            </span>
                            <span><i :class="option.icon" /> {{ option.label }}</span>
                            <i v-if="ui.theme === option.value" class="pi pi-check active-check" />
                        </button>
                    </div>
                </div>

                <div class="field">
                    <label>Message density</label>
                    <SelectButton v-model="ui.density" :options="densityOptions" option-label="label" option-value="value" @update:model-value="ui.setDensity" />
                </div>

                <div class="switch-row">
                    <div><strong>Reduce motion</strong><small>Turn off transitions and animated flourishes.</small></div>
                    <ToggleSwitch :model-value="ui.reduceMotion" @update:model-value="ui.setReduceMotion" />
                </div>

                <div class="switch-row">
                    <div><strong>Message sounds</strong><small>A soft chime when a teammate messages you.</small></div>
                    <ToggleSwitch :model-value="ui.preferences.messageSounds" @update:model-value="ui.setPreference('messageSounds', $event)" />
                </div>

                <div class="switch-row">
                    <div><strong>Notifications</strong><small>Alert you for new messages and thread replies, including when SmackChat is closed.</small></div>
                    <ToggleSwitch :model-value="ui.preferences.desktopNotifications" @update:model-value="setNotifications" />
                </div>
            </section>

            <section class="panel ai-panel">
                <header class="card-head">
                    <div><p class="card-kicker">On-Device AI (Private &amp; E2E Safe)</p><h2>AI that stays on this device</h2></div>
                    <Tag :value="aiStatusLabel" :severity="ai.modelState === 'ready' ? 'success' : 'secondary'" />
                </header>

                <div class="detail-rows">
                    <div><span>Engine</span><strong>{{ aiEngineName }}</strong></div>
                    <div><span>Status</span><strong>{{ aiStatusLabel }}</strong></div>
                    <div v-if="ai.engine === 'transformers-local'"><span>Model size</span><strong>{{ ai.modelSizeHint }}</strong></div>
                    <div><span>Data leaving your device</span><strong>None</strong></div>
                </div>

                <div v-if="ai.modelState === 'downloading'" class="ai-download-row">
                    <div class="ai-download-copy">
                        <i class="pi pi-cloud-download" />
                        <span>{{ ai.downloadLabel }}</span>
                    </div>
                    <ProgressBar :value="ai.downloadPercent" :show-value="false" class="ai-download-bar" />
                </div>

                <p class="fine">
                    Rewrites, drafts, summaries, and agent actions run inside this browser tab. Message text is
                    end-to-end encrypted and is never sent to the server for the model to read. A drafted
                    message is posted, and a clock change runs immediately. Re-downloading picks the fastest
                    model this browser can run.
                </p>

                <div class="ai-actions">
                    <Button
                        v-if="aiCanDownload"
                        label="Download Offline AI"
                        icon="pi pi-download"
                        size="small"
                        :loading="aiBusy"
                        @click="downloadAiModel"
                    />
                    <Button
                        v-if="aiCanRedownload"
                        label="Delete & Re-download"
                        icon="pi pi-refresh"
                        severity="secondary"
                        outlined
                        size="small"
                        :disabled="aiBusy"
                        @click="redownloadAiModel"
                    />
                    <Button
                        v-if="aiCanDelete"
                        label="Delete Downloaded Model"
                        icon="pi pi-trash"
                        severity="danger"
                        text
                        size="small"
                        :disabled="aiBusy"
                        @click="deleteAiModel"
                    />
                    <span v-if="ai.engine === 'gemini-nano' && ai.modelState === 'ready'" class="ai-ready-badge">⚡ Gemini Nano Ready</span>
                </div>
            </section>

            <section class="panel workspace-panel">
                <header class="card-head">
                    <div><p class="card-kicker">Workspace</p><h2>{{ office.workspace.name }}</h2></div>
                    <Tag :value="office.workspace.plan === 'archive' ? 'Forever archive' : `${office.workspace.retention_days}-day history`" :severity="office.workspace.plan === 'archive' ? 'success' : 'secondary'" />
                </header>

                <div class="workspace-identity">
                    <Avatar
                        :image="office.workspace.logo_url || undefined"
                        :label="!office.workspace.logo_url ? office.workspace.name.slice(0, 1) : undefined"
                        shape="circle"
                        size="xlarge"
                        class="ws-mark"
                    />
                    <div>
                        <strong>{{ office.workspace.name }}</strong>
                        <small>{{ roleLabel }} · {{ office.workspace.seats }} seats · {{ office.workspace.timezone }}</small>
                        <div v-if="office.manages" class="workspace-logo-actions">
                            <input ref="logoInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden @change="uploadLogo">
                            <Button label="Upload company logo" icon="pi pi-image" size="small" severity="secondary" text :loading="logoBusy" @click="logoInput?.click()" />
                            <Button v-if="office.workspace.logo_url" label="Remove" icon="pi pi-times" size="small" severity="danger" text :disabled="logoBusy" @click="removeLogo" />
                        </div>
                    </div>
                </div>

                <div class="detail-rows">
                    <div><span>Your role</span><strong>{{ roleLabel }}</strong></div>
                    <div><span>Office hours</span><strong>{{ office.workspace.work_start }}–{{ office.workspace.work_end }}</strong></div>
                    <div><span>Break allowance</span><strong>{{ office.workspace.break_allowance_minutes ? minutesLabel(office.workspace.break_allowance_minutes) : 'None' }}</strong></div>
                    <div><span>Expected day</span><strong>{{ minutesLabel(office.workspace.standard_day_minutes || 480) }}</strong></div>
                    <div><span>Working days</span><strong>{{ office.workspace.work_days?.length || 5 }} days a week</strong></div>
                    <div><span>History</span><strong>{{ office.workspace.plan === 'archive' ? 'Kept forever' : `${office.workspace.retention_days} days` }}</strong></div>
                    <div><span>Huddles</span><strong>{{ office.workspace.plan === 'archive' ? `Groups up to ${office.workspace.calls?.limit || 8}` : 'One to one (free)' }}</strong></div>
                </div>

                <div class="workspace-actions">
                    <Button label="Schedule" icon="pi pi-calendar" severity="secondary" outlined size="small" @click="router.push(`/w/${office.slug}/hours`)" />
                    <Button label="People" icon="pi pi-users" severity="secondary" outlined size="small" @click="router.push(`/w/${office.slug}/members`)" />
                    <Button label="Plans" icon="pi pi-sparkles" severity="secondary" outlined size="small" @click="router.push(`/w/${office.slug}/billing`)" />
                </div>

                <Divider />

                <div v-if="!office.isOwner" class="danger-zone">
                    <div>
                        <strong>Leave this workspace</strong>
                        <p>Your messages stay. You lose access until you are invited again.</p>
                    </div>
                    <Button label="Leave" icon="pi pi-sign-out" severity="danger" text @click="leaveWorkspace" />
                </div>
                <p v-else class="fine">The owner stays with the workspace. Transfer ownership is not available yet.</p>
            </section>

            <section class="panel realtime-panel">
                <header class="card-head">
                    <div><p class="card-kicker">Realtime</p><h2>Live delivery</h2></div>
                    <Tag :value="realtimeTagLabel" :severity="realtimeTagSeverity" />
                </header>

                <div class="detail-rows">
                    <div><span>Messages and calls</span><strong>{{ realtimeLabel }}</strong></div>
                    <div v-if="office.workspace.realtime?.masked"><span>Workspace key</span><strong>{{ office.workspace.realtime.masked }}</strong></div>
                    <div><span>Fallback</span><strong>Polling — chat always works</strong></div>
                    <div><span>Ably app id</span><strong>{{ office.workspace.realtime?.mode === 'workspace' ? 'This workspace' : 'Server default' }}</strong></div>
                </div>

                <template v-if="office.isOwner">
                    <div class="field realtime-key-field">
                        <label>Your own Ably key</label>
                        <Password
                            v-model="ablyKey"
                            toggle-mask
                            :feedback="false"
                            placeholder="appId.keyId:secret"
                            autocomplete="off"
                            fluid
                        />
                    </div>
                    <p class="fine">
                        Bring the office's own Ably app. The key is stored encrypted, never shown again, and only used to mint
                        short-lived tokens for people in this workspace. Leave it empty to use the server key, or use polling
                        when neither is set. Test connection checks the saved key.
                    </p>
                    <div class="workspace-actions">
                        <Button label="Save key" icon="pi pi-check" size="small" :loading="ablyBusy" :disabled="!ablyKey.trim()" @click="saveAblyKey" />
                        <Button label="Test connection" icon="pi pi-bolt" severity="secondary" outlined size="small" :loading="ablyTesting" @click="testRealtime" />
                        <Button v-if="office.workspace.realtime?.custom" label="Remove key" icon="pi pi-trash" severity="danger" text size="small" @click="removeAblyKey" />
                    </div>
                </template>
                <p v-else class="fine">Only the workspace owner can change the Ably key.</p>
            </section>

            <section class="panel activity-panel">
                <header class="card-head">
                    <div><p class="card-kicker">Account</p><h2>At a glance</h2></div>
                </header>
                <div class="detail-rows">
                    <div><span>Email</span><strong>{{ auth.user.email }}</strong></div>
                    <div><span>Workspaces</span><strong>{{ auth.user.workspaces?.length || 1 }}</strong></div>
                    <div><span>Last seen</span><strong>{{ auth.user.last_seen_at ? timeAgo(auth.user.last_seen_at) : 'Just now' }}</strong></div>
                    <div><span>Locale</span><strong>{{ locale }}</strong></div>
                </div>
                <Divider />
                <div class="account-note">
                    <i class="pi pi-lock" />
                    <p>Message text is encrypted on this device before it is sent. The server stores and delivers the ciphertext, and only devices in the conversation can read it. The history window is controlled by the workspace plan.</p>
                </div>

                <div class="workspace-actions">
                    <Button label="Download my data" icon="pi pi-download" severity="secondary" outlined size="small" @click="downloadMyData" />
                    <Button label="Delete account" icon="pi pi-trash" severity="danger" text size="small" @click="deleteOpen = true" />
                    <Button label="Open command palette" icon="pi pi-search" severity="secondary" outlined size="small" @click="ui.openCommand()" />
                </div>
            </section>
        </div>

        <Dialog v-model:visible="deleteOpen" modal header="Delete account" :style="{ width: 'min(92vw, 440px)' }">
            <p>
                This removes your profile and signs you out everywhere. Workspaces where you are the only member are deleted.
                Messages you sent stay in their conversations without your name.
            </p>
            <div class="field">
                <label>Confirm with your password</label>
                <Password v-model="deletePassword" toggle-mask :feedback="false" placeholder="Current password" fluid />
            </div>
            <template #footer>
                <Button label="Cancel" text severity="secondary" @click="deleteOpen = false" />
                <Button label="Delete account" severity="danger" :loading="deleting" :disabled="!deletePassword" @click="deleteAccount" />
            </template>
        </Dialog>
    </div>
</template>
