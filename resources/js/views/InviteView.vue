<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api, { ensureCsrf, errorText } from '@/api';
import { useAuthStore } from '@/stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const invite = ref(null);
const error = ref('');
const loadError = ref('');
const busy = ref(false);
const form = ref({ name: '', password: '', password_confirmation: '' });

const roleLabel = computed(() => (invite.value?.role || 'member').replace(/^\w/, (letter) => letter.toUpperCase()));

onMounted(async () => {
    try {
        const { data } = await api.get(`/invitations/${route.params.token}`);
        invite.value = data.invitation;
    } catch (caught) {
        loadError.value = errorText(caught, 'This invite could not be found.');
    }
});

async function accept() {
    error.value = '';
    busy.value = true;
    try {
        await ensureCsrf();
        const payload = auth.user ? {} : form.value;
        const { data } = await api.post(`/invitations/${route.params.token}/accept`, payload);
        const password = auth.user ? null : form.value.password;
        await auth.adopt(data.user, password);
        router.push(`/w/${data.workspace_slug}`);
    } catch (caught) {
        error.value = errorText(caught);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="invite-page">
        <section class="invite-story">
            <div class="auth-brand">
                <span class="brand-mark"><i class="pi pi-comment" /></span>
                <span class="brand-word">smack<span class="brand-dot">.</span></span>
            </div>
            <div class="invite-story-copy">
                <p class="eyebrow light">You are invited</p>
                <h1 v-if="invite">Join {{ invite.workspace }}.</h1>
                <h1 v-else>You are invited.</h1>
                <p v-if="invite">A workspace for the team's conversations, decisions, and the hours that make the work real.</p>
                <div v-if="invite" class="invite-role-card">
                    <span class="invite-role-icon"><i class="pi pi-envelope" /></span>
                    <div>
                        <strong>{{ invite.email }}</strong>
                        <small>Invite for {{ roleLabel }} access</small>
                    </div>
                    <Tag :value="roleLabel" severity="success" />
                </div>
                <div class="invite-points">
                    <span><i class="pi pi-check" /> Channels, threads, and direct messages</span>
                    <span><i class="pi pi-check" /> A floor clock that files your day</span>
                    <span><i class="pi pi-check" /> Searchable history, end-to-end encrypted</span>
                </div>
            </div>
        </section>

        <section class="invite-card-wrap">
            <div class="invite-card">
                <div v-if="loadError" class="invite-state">
                    <span class="invite-state-icon"><i class="pi pi-exclamation-circle" /></span>
                    <h2>This invite is not available</h2>
                    <p>{{ loadError }}</p>
                    <Button label="Go to login" icon="pi pi-arrow-right" @click="router.push('/login')" />
                </div>

                <div v-else-if="!invite" class="invite-state">
                    <i class="pi pi-spin pi-spinner invite-spinner" />
                    <p>Checking the invite…</p>
                </div>

                <div v-else-if="!invite.open" class="invite-state">
                    <span class="invite-state-icon amber"><i class="pi pi-clock" /></span>
                    <h2>This invite has expired</h2>
                    <p>Ask the owner or an admin to send a fresh one. They expire after 14 days.</p>
                    <Button label="Back to login" severity="secondary" outlined @click="router.push('/login')" />
                </div>

                <template v-else>
                    <div class="auth-card-head">
                        <p class="eyebrow">Accept invite</p>
                        <h2>{{ auth.user ? 'One click and you are in' : 'Create your account' }}</h2>
                        <p>{{ auth.user ? 'You are signed in. Accept the invite to join the workspace.' : 'This invite is tied to the email above. Set a password to finish.' }}</p>
                    </div>

                    <Message v-if="auth.user" severity="success" :closable="false">
                        Signed in as {{ auth.user.email }}
                    </Message>

                    <form v-else class="auth-form" @submit.prevent="accept">
                        <div class="field">
                            <label>Your name</label>
                            <InputText v-model="form.name" placeholder="Ada Lovelace" autocomplete="name" fluid />
                        </div>
                        <div class="field">
                            <label>Password</label>
                            <Password v-model="form.password" toggle-mask feedback placeholder="At least 8 characters" autocomplete="new-password" fluid />
                        </div>
                        <div class="field">
                            <label>Confirm password</label>
                            <Password v-model="form.password_confirmation" toggle-mask :feedback="false" autocomplete="new-password" fluid />
                        </div>
                        <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
                        <Button type="submit" label="Join the workspace" icon="pi pi-arrow-right" :loading="busy" fluid />
                    </form>

                    <template v-if="auth.user">
                        <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
                        <Button label="Join the workspace" icon="pi pi-arrow-right" :loading="busy" fluid @click="accept" />
                    </template>

                    <div v-if="!auth.user" class="invite-switch">
                        <span>Already have an account?</span>
                        <RouterLink :to="{ path: '/login', query: { next: `/invite/${route.params.token}` } }">Log in first</RouterLink>
                    </div>
                </template>
            </div>
        </section>
    </div>
</template>
