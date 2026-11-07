<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import api, { errorText } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useOfficeStore } from '@/stores/office';
import { formatFullDay, timeAgo } from '@/time';

const office = useOfficeStore();
const auth = useAuthStore();
const router = useRouter();
const toast = useToast();
const confirm = useConfirm();

const search = ref('');
const roleFilter = ref(null);
const inviteOpen = ref(false);
const personOpen = ref(false);
const selectedPerson = ref(null);
const inviteForm = ref({ email: '', role: 'member' });
const latestInvite = ref(null);
const busy = ref(false);
const roleBusy = ref(false);

const roleOptions = [
    { label: 'All roles', value: null },
    { label: 'Owner', value: 'owner' },
    { label: 'Admin', value: 'admin' },
    { label: 'Member', value: 'member' },
];
const inviteRoles = [
    { label: 'Member', value: 'member' },
    { label: 'Admin', value: 'admin' },
];

const filteredMembers = computed(() => office.members
    .filter((person) => (roleFilter.value ? person.role === roleFilter.value : true))
    .filter((person) => {
        const needle = search.value.trim().toLowerCase();
        if (!needle) return true;
        return `${person.name} ${person.email} ${person.title || ''} ${person.status_text || ''}`.toLowerCase().includes(needle);
    })
    .sort((a, b) => Number(b.online) - Number(a.online) || a.name.localeCompare(b.name)));
const stats = computed(() => ({
    seats: office.members.length,
    online: office.onlineMembers.length,
    admins: office.members.filter((person) => person.role === 'owner' || person.role === 'admin').length,
    pending: office.invitations.length,
}));
const roleSeverity = (role) => (role === 'owner' ? 'contrast' : role === 'admin' ? 'warn' : 'secondary');

async function invite() {
    busy.value = true;
    try {
        const { data } = await api.post(`/workspaces/${office.slug}/invitations`, inviteForm.value);
        latestInvite.value = data.invitation;
        inviteForm.value = { email: '', role: 'member' };
        await office.refreshMembers();
        await copy(data.invitation.url, false);
        toast.add({ severity: 'success', summary: 'Invite ready', detail: 'The link was copied. You can also copy it below.', life: 3600 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Invite', detail: errorText(error), life: 3500 });
    } finally {
        busy.value = false;
    }
}

async function copy(url, notify = true) {
    try {
        await navigator.clipboard.writeText(url);
        if (notify) {
            toast.add({ severity: 'success', summary: 'Link copied', life: 1800 });
        }
    } catch {
        if (notify) {
            toast.add({ severity: 'warn', summary: 'Copy unavailable', detail: url, life: 5000 });
        }
    }
}

function openPerson(person) {
    selectedPerson.value = { ...person };
    personOpen.value = true;
}

async function startDm(person) {
    try {
        const { data } = await api.post(`/workspaces/${office.slug}/dms`, { user_id: person.id });
        personOpen.value = false;
        router.push(`/w/${office.slug}/c/${data.slug}`);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Direct message', detail: errorText(error), life: 3000 });
    }
}

async function updateRole(role) {
    if (!selectedPerson.value || role === selectedPerson.value.role) return;
    roleBusy.value = true;
    try {
        await api.patch(`/workspaces/${office.slug}/members/${selectedPerson.value.id}`, { role });
        await office.refreshMembers();
        selectedPerson.value.role = role;
        toast.add({ severity: 'success', summary: 'Role updated', life: 2200 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Role', detail: errorText(error), life: 3000 });
    } finally {
        roleBusy.value = false;
    }
}

function removePerson(person) {
    const leaving = person.id === auth.user.id;
    confirm.require({
        message: leaving ? 'Leave this workspace? You can only rejoin with an invite.' : `Remove ${person.name} from this workspace?`,
        header: leaving ? 'Leave workspace' : 'Remove person',
        icon: 'pi pi-sign-out',
        rejectProps: { label: 'Cancel', severity: 'secondary', text: true },
        acceptProps: { label: leaving ? 'Leave' : 'Remove', severity: 'danger' },
        accept: async () => {
            try {
                await api.delete(`/workspaces/${office.slug}/members/${person.id}`);
                personOpen.value = false;
                if (leaving) {
                    await auth.fetchMe();
                    router.push('/app');
                    return;
                }
                await office.refreshMembers();
                toast.add({ severity: 'success', summary: 'Person removed', life: 2200 });
            } catch (error) {
                toast.add({ severity: 'error', summary: 'Remove person', detail: errorText(error), life: 3200 });
            }
        },
    });
}

function revoke(invite) {
    confirm.require({
        message: `Revoke the invite for ${invite.email}?`,
        header: 'Revoke invite',
        icon: 'pi pi-times-circle',
        rejectProps: { label: 'Keep it', severity: 'secondary', text: true },
        acceptProps: { label: 'Revoke', severity: 'danger' },
        accept: async () => {
            await api.delete(`/workspaces/${office.slug}/invitations/${invite.id}`);
            if (latestInvite.value?.id === invite.id) {
                latestInvite.value = null;
            }
            await office.refreshMembers();
            toast.add({ severity: 'success', summary: 'Invite revoked', life: 2000 });
        },
    });
}
</script>

<template>
    <div class="page members-page">
        <header class="page-head">
            <div>
                <p class="eyebrow">People <span class="dot-sep">·</span> {{ office.workspace.name }}</p>
                <h1 class="display-title">Who is in the room</h1>
                <p class="lede">Find a teammate, see who is around, and manage the people who share this workspace.</p>
            </div>
            <div class="page-tools">
                <Button
                    v-if="office.manages"
                    label="Invite people"
                    icon="pi pi-user-plus"
                    @click="inviteOpen = true"
                />
            </div>
        </header>

        <div class="stat-strip">
            <div class="stat-card">
                <span class="stat-icon"><i class="pi pi-users" /></span>
                <div><strong>{{ stats.seats }}</strong><small>Seats in workspace</small></div>
            </div>
            <div class="stat-card">
                <span class="stat-icon green"><i class="pi pi-circle-fill" /></span>
                <div><strong>{{ stats.online }}</strong><small>Online right now</small></div>
            </div>
            <div class="stat-card">
                <span class="stat-icon amber"><i class="pi pi-shield" /></span>
                <div><strong>{{ stats.admins }}</strong><small>Owner and admins</small></div>
            </div>
            <div class="stat-card">
                <span class="stat-icon blue"><i class="pi pi-envelope" /></span>
                <div><strong>{{ stats.pending }}</strong><small>Pending invites</small></div>
            </div>
        </div>

        <section class="panel directory-panel">
            <header class="directory-tools">
                <IconField class="directory-search">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="search" placeholder="Search people, email, or role…" fluid />
                </IconField>
                <Select v-model="roleFilter" :options="roleOptions" option-label="label" option-value="value" class="role-filter" />
                <Tag :value="`${filteredMembers.length} shown`" severity="secondary" />
            </header>

            <div class="people-grid">
                <article
                    v-for="person in filteredMembers"
                    :key="person.id"
                    class="person-card"
                    :class="{ me: person.id === auth.user.id }"
                >
                    <div class="person-top">
                        <span class="presence-avatar" :class="{ online: person.online }">
                            <Avatar
                                :label="person.name.slice(0, 1)"
                                shape="circle"
                                size="large"
                                :style="{ background: person.avatar_color, color: '#fff' }"
                            />
                        </span>
                        <div class="person-copy">
                            <strong>{{ person.name }} <span v-if="person.id === auth.user.id" class="you-tag">you</span></strong>
                            <small>{{ person.title || person.email }}</small>
                        </div>
                        <Tag :value="person.role" :severity="roleSeverity(person.role)" />
                    </div>

                    <p class="person-status">
                        <span class="status-dot" :class="{ online: person.online }" />
                        {{ person.status_text || (person.online ? 'Online now' : person.last_seen_at ? `Last seen ${timeAgo(person.last_seen_at)}` : 'Away') }}
                    </p>

                    <div class="person-foot">
                        <span class="person-joined">Joined {{ person.joined_at ? formatFullDay(person.joined_at) : 'recently' }}</span>
                        <div>
                            <Button
                                v-if="person.id !== auth.user.id"
                                icon="pi pi-send"
                                text
                                rounded
                                size="small"
                                aria-label="Send direct message"
                                v-tooltip.top="'Message'"
                                @click="startDm(person)"
                            />
                            <Button icon="pi pi-ellipsis-h" text rounded size="small" aria-label="Person details" @click="openPerson(person)" />
                        </div>
                    </div>
                </article>
                <div v-if="!filteredMembers.length" class="empty-state">
                    <i class="pi pi-users" />
                    <h2>No people match</h2>
                    <p>Try another name or clear the role filter.</p>
                </div>
            </div>
        </section>

        <section v-if="office.manages && office.invitations.length" class="panel invites-panel">
            <header class="card-head">
                <div><p class="card-kicker">Invites</p><h2>Waiting on an answer</h2></div>
                <Tag :value="`${office.invitations.length} pending`" severity="warn" />
            </header>
            <ul class="invite-list">
                <li v-for="incoming in office.invitations" :key="incoming.id">
                    <span class="stat-icon blue"><i class="pi pi-envelope" /></span>
                    <span class="invite-copy">
                        <strong>{{ incoming.email }}</strong>
                        <small>{{ incoming.role }} · expires {{ formatFullDay(incoming.expires_at) }}</small>
                    </span>
                    <Tag :value="incoming.role" severity="secondary" />
                    <Button label="Copy link" icon="pi pi-copy" text size="small" @click="copy(incoming.url)" />
                    <Button icon="pi pi-times" text rounded size="small" severity="danger" aria-label="Revoke invite" @click="revoke(incoming)" />
                </li>
            </ul>
        </section>

        <section v-if="latestInvite" class="panel latest-invite">
            <div class="latest-invite-copy">
                <span class="stat-icon green"><i class="pi pi-check" /></span>
                <div>
                    <strong>Invite ready for {{ latestInvite.email }}</strong>
                    <small>Share this private link. It expires {{ formatFullDay(latestInvite.expires_at) }}.</small>
                </div>
            </div>
            <div class="latest-invite-link">
                <InputText :model-value="latestInvite.url" readonly fluid />
                <Button icon="pi pi-copy" label="Copy" @click="copy(latestInvite.url)" />
            </div>
        </section>

        <Dialog v-model:visible="inviteOpen" modal :style="{ width: '30rem' }" class="smack-dialog">
            <template #header>
                <div class="dialog-title">
                    <span class="dialog-icon"><i class="pi pi-user-plus" /></span>
                    <div><strong>Invite someone</strong><small>They join this workspace with the role you choose.</small></div>
                </div>
            </template>
            <form class="form-stack" @submit.prevent="invite">
                <FloatLabel variant="on">
                    <InputText id="invite-email" v-model="inviteForm.email" type="email" fluid />
                    <label for="invite-email">Work email</label>
                </FloatLabel>
                <div class="field">
                    <label>Role</label>
                    <Select v-model="inviteForm.role" :options="inviteRoles" option-label="label" option-value="value" fluid />
                </div>
                <p class="fine">Admins can manage people and channels. Members can chat and file their own hours.</p>
                <Message v-if="latestInvite" severity="success" :closable="false">
                    Latest link for {{ latestInvite.email }}: {{ latestInvite.url }}
                </Message>
                <div class="dialog-actions">
                    <Button label="Close" text severity="secondary" type="button" @click="inviteOpen = false" />
                    <Button type="submit" label="Create invite" icon="pi pi-send" :loading="busy" :disabled="!inviteForm.email" />
                </div>
            </form>
        </Dialog>

        <Dialog v-model:visible="personOpen" modal :style="{ width: '32rem' }" class="smack-dialog">
            <template #header>
                <div class="dialog-title">
                    <span class="dialog-icon"><i class="pi pi-user" /></span>
                    <div><strong>Person details</strong><small>Presence, role, and a shortcut to message.</small></div>
                </div>
            </template>
            <div v-if="selectedPerson" class="person-detail">
                <div class="person-detail-head">
                    <span class="presence-avatar" :class="{ online: selectedPerson.online }">
                        <Avatar :label="selectedPerson.name.slice(0, 1)" shape="circle" size="xlarge" :style="{ background: selectedPerson.avatar_color, color: '#fff' }" />
                    </span>
                    <div>
                        <h2>{{ selectedPerson.name }}</h2>
                        <p>{{ selectedPerson.title || 'Teammate' }} · <span :class="{ 'presence-on': selectedPerson.online }">{{ selectedPerson.online ? 'Online now' : 'Away' }}</span></p>
                    </div>
                    <Tag :value="selectedPerson.role" :severity="roleSeverity(selectedPerson.role)" />
                </div>

                <div class="detail-rows">
                    <div><span>Email</span><strong>{{ selectedPerson.email }}</strong></div>
                    <div><span>Status</span><strong>{{ selectedPerson.status_text || 'No status set' }}</strong></div>
                    <div><span>Joined</span><strong>{{ selectedPerson.joined_at ? formatFullDay(selectedPerson.joined_at) : 'Recently' }}</strong></div>
                    <div><span>Role</span><strong>{{ selectedPerson.role }}</strong></div>
                </div>

                <div v-if="office.isOwner && selectedPerson.role !== 'owner'" class="field role-field">
                    <label>Change role</label>
                    <Select
                        :model-value="selectedPerson.role"
                        :options="inviteRoles"
                        option-label="label"
                        option-value="value"
                        :loading="roleBusy"
                        fluid
                        @update:model-value="updateRole"
                    />
                </div>

                <div class="dialog-actions">
                    <Button
                        v-if="selectedPerson.id !== auth.user.id"
                        label="Send message"
                        icon="pi pi-send"
                        @click="startDm(selectedPerson)"
                    />
                    <Button
                        v-if="selectedPerson.role !== 'owner' && (office.manages || selectedPerson.id === auth.user.id)"
                        :label="selectedPerson.id === auth.user.id ? 'Leave workspace' : 'Remove'"
                        icon="pi pi-sign-out"
                        severity="danger"
                        outlined
                        @click="removePerson(selectedPerson)"
                    />
                </div>
            </div>
        </Dialog>
    </div>
</template>
