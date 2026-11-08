<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import api, { errorText } from '@/api';
import { disablePush, ensurePush } from '@/push';
import { useAuthStore } from '@/stores/auth';
import { useOfficeStore } from '@/stores/office';
import { useUiStore } from '@/stores/ui';
import { timeAgo } from '@/time';

const auth = useAuthStore();
const office = useOfficeStore();
const ui = useUiStore();
const router = useRouter();
const toast = useToast();
const confirm = useConfirm();

const busy = ref(false);
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
const initials = computed(() => auth.user.name.slice(0, 1).toUpperCase());
const roleLabel = computed(() => (office.workspace?.role || 'member').replace(/^\w/, (letter) => letter.toUpperCase()));

function syncForm() {
    form.name = auth.user.name;
    form.title = auth.user.title || '';
    form.status_emoji = auth.user.status_emoji || '';
    form.status_text = auth.user.status_text || '';
    form.timezone = auth.user.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
}

watch(() => auth.user, syncForm, { immediate: true });

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
                    <span class="presence-avatar online">
                        <Avatar :label="initials" shape="circle" size="xlarge" :style="{ background: auth.user.avatar_color, color: '#fff' }" />
                    </span>
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

            <section class="panel workspace-panel">
                <header class="card-head">
                    <div><p class="card-kicker">Workspace</p><h2>{{ office.workspace.name }}</h2></div>
                    <Tag :value="office.workspace.plan === 'archive' ? 'Forever archive' : `${office.workspace.retention_days}-day history`" :severity="office.workspace.plan === 'archive' ? 'success' : 'secondary'" />
                </header>

                <div class="workspace-identity">
                    <Avatar :label="office.workspace.name.slice(0, 1)" shape="circle" size="xlarge" class="ws-mark" />
                    <div>
                        <strong>{{ office.workspace.name }}</strong>
                        <small>{{ roleLabel }} · {{ office.workspace.seats }} seats · {{ office.workspace.timezone }}</small>
                    </div>
                </div>

                <div class="detail-rows">
                    <div><span>Your role</span><strong>{{ roleLabel }}</strong></div>
                    <div><span>Office hours</span><strong>{{ office.workspace.work_start }}–{{ office.workspace.work_end }}</strong></div>
                    <div><span>Working days</span><strong>{{ office.workspace.work_days?.length || 5 }} days a week</strong></div>
                    <div><span>History</span><strong>{{ office.workspace.plan === 'archive' ? 'Kept forever' : `${office.workspace.retention_days} days` }}</strong></div>
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
                    <p>Message text is encrypted at rest with the application key. The history window is controlled by the workspace plan.</p>
                </div>
                <Button label="Open command palette" icon="pi pi-search" severity="secondary" outlined size="small" @click="ui.openCommand()" />
            </section>
        </div>
    </div>
</template>
