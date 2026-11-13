<script setup>
import { computed, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import BrandMark from '@/components/BrandMark.vue';
import { useAuthStore } from '@/stores/auth';
import { errorText } from '@/api';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const mode = ref(route.query.mode === 'register' ? 'register' : 'login');
const step = ref('form');
const modes = [
    { label: 'Log in', value: 'login' },
    { label: 'New workspace', value: 'register' },
];
const error = ref('');
const notice = ref('');
const busy = ref(false);
const resending = ref(false);
const otp = ref('');
const pendingEmail = ref('');
const form = ref({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    workspace_name: '',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
});
const isDev = import.meta.env.DEV;
const isRegister = computed(() => mode.value === 'register');
const verifying = computed(() => isRegister.value && step.value === 'verify');

function switchMode(next) {
    mode.value = next;
    step.value = 'form';
    error.value = '';
    notice.value = '';
    otp.value = '';
    pendingEmail.value = '';
}

async function submit() {
    error.value = '';
    notice.value = '';
    busy.value = true;
    try {
        if (mode.value === 'login') {
            await auth.login({ email: form.value.email, password: form.value.password });
            router.push(route.query.next || '/app');
            return;
        }

        if (verifying.value) {
            const slug = await auth.verifyRegistration({
                email: pendingEmail.value || form.value.email,
                code: String(otp.value || '').replace(/\D/g, '').slice(0, 6),
                password: form.value.password,
            });
            router.push(`/w/${slug}`);
            return;
        }

        const result = await auth.register(form.value);
        pendingEmail.value = result.email || form.value.email;
        notice.value = result.message || `A six-digit code is on its way to ${pendingEmail.value}.`;
        step.value = 'verify';
        otp.value = '';
    } catch (caught) {
        error.value = errorText(caught, 'Check the form and try again.');
    } finally {
        busy.value = false;
    }
}

async function resend() {
    error.value = '';
    resending.value = true;
    try {
        const result = await auth.resendRegistrationCode(pendingEmail.value || form.value.email);
        notice.value = result.message || 'A fresh code is on its way.';
    } catch (caught) {
        error.value = errorText(caught, 'Could not resend the code.');
    } finally {
        resending.value = false;
    }
}

function demo(email) {
    switchMode('login');
    form.value.email = email;
    form.value.password = 'password';
    submit();
}
</script>

<template>
    <div class="auth-page">
        <section class="auth-story">
            <div class="auth-brand">
                <RouterLink to="/" class="auth-brand-link">
                    <BrandMark size="lg" />
                    <span class="brand-word">smack<span class="brand-dot">.</span></span>
                </RouterLink>
                <span class="auth-brand-note">Team chat, with a clock</span>
            </div>

            <div class="auth-story-copy">
                <p class="eyebrow light">Your team's day, in one place</p>
                <h1>Conversations that know when the workday starts.</h1>
                <p>Channels, direct messages, a floor clock, and attendance that files itself. SmackChat keeps the work and the hours in the same room.</p>
                <div class="auth-features">
                    <div><i class="pi pi-comments" /><span><strong>Rooms that stay tidy</strong><small>Channels, threads, rich messages, and search.</small></span></div>
                    <div><i class="pi pi-stopwatch" /><span><strong>The floor clock</strong><small>Check in, take breaks, and file the day automatically.</small></span></div>
                    <div><i class="pi pi-chart-line" /><span><strong>Attendance you can read</strong><small>Live presence, monthly heatmaps, and CSV exports.</small></span></div>
                </div>
            </div>

            <div class="auth-preview">
                <aside>
                    <div class="preview-brand">smack<span>.</span></div>
                    <div class="preview-nav on"><i class="pi pi-home" /> Today</div>
                    <div class="preview-nav"><i class="pi pi-stopwatch" /> Attendance</div>
                    <div class="preview-nav"><i class="pi pi-users" /> People</div>
                    <span class="preview-label">Channels</span>
                    <div class="preview-channel on"><i class="pi pi-hash" /> general <b>3</b></div>
                    <div class="preview-channel"><i class="pi pi-hash" /> design</div>
                    <div class="preview-channel"><i class="pi pi-lock" /> launch-room</div>
                </aside>
                <div class="preview-main">
                    <header>
                        <span><i class="pi pi-hash" /> general</span>
                        <div class="preview-presence">
                            <i style="background: #e23d2b">A</i>
                            <i style="background: #0f6e62">G</i>
                            <i style="background: #1d4e89">L</i>
                        </div>
                    </header>
                    <div class="preview-messages">
                        <div class="preview-msg">
                            <i style="background: #e23d2b">A</i>
                            <div><strong>Ada Lovelace</strong><small>9:02</small><p>Floor clock is live. Checkout will file the day into Timesheets.</p></div>
                        </div>
                        <div class="preview-msg">
                            <i style="background: #0f6e62">G</i>
                            <div><strong>Grace Hopper</strong><small>9:06</small><p>The compiler thread is moving. I left the long notes in a thread.</p></div>
                        </div>
                        <div class="preview-msg mine">
                            <div><strong>You</strong><small>9:08</small><p>Perfect. I’ll post the launch frames before noon.</p></div>
                        </div>
                    </div>
                    <div class="preview-composer"><i class="pi pi-face-smile" /><span>Message #general</span><i class="pi pi-arrow-up" /></div>
                </div>
            </div>

            <div class="auth-footer">
                <span><i class="pi pi-lock" /> Encrypted at rest</span>
                <span><i class="pi pi-bolt" /> Live when Ably is connected</span>
                <span><i class="pi pi-clock" /> 90-day history or forever</span>
            </div>
        </section>

        <section class="auth-card-wrap">
            <div class="auth-card">
                <div class="auth-card-head">
                    <p class="eyebrow">{{ verifying ? 'Almost there' : (isRegister ? 'Start fresh' : 'Welcome back') }}</p>
                    <h2>{{ verifying ? 'Check your email' : (isRegister ? 'Open a workspace' : 'Log in to your workspace') }}</h2>
                    <p>
                        <template v-if="verifying">
                            Enter the six-digit code we sent to <strong>{{ pendingEmail }}</strong> to finish creating the workspace.
                        </template>
                        <template v-else-if="isRegister">
                            A company, a floor clock, and a place for the team to talk. We’ll verify your email before the workspace opens.
                        </template>
                        <template v-else>
                            Pick up the conversation, the hours, and the day.
                        </template>
                    </p>
                </div>

                <SelectButton
                    v-if="!verifying"
                    :model-value="mode"
                    :options="modes"
                    option-label="label"
                    option-value="value"
                    :allow-empty="false"
                    class="auth-mode"
                    @update:model-value="switchMode"
                />

                <form class="auth-form" @submit.prevent="submit">
                    <template v-if="verifying">
                        <div class="field otp-field">
                            <label>Verification code</label>
                            <InputOtp v-model="otp" integer-only :length="6" class="auth-otp" />
                            <small class="fine">Codes expire in 10 minutes.</small>
                        </div>
                    </template>

                    <template v-else>
                        <template v-if="isRegister">
                            <div class="form-grid two">
                                <div class="field">
                                    <label>Your name</label>
                                    <InputText v-model="form.name" placeholder="Ada Lovelace" autocomplete="name" fluid />
                                </div>
                                <div class="field">
                                    <label>Company</label>
                                    <InputText v-model="form.workspace_name" placeholder="Northwind Studio" autocomplete="organization" fluid />
                                </div>
                            </div>
                        </template>

                        <div class="field">
                            <label>Work email</label>
                            <IconField>
                                <InputIcon class="pi pi-envelope" />
                                <InputText v-model="form.email" type="email" placeholder="you@company.com" autocomplete="username" fluid />
                            </IconField>
                        </div>

                        <div class="field">
                            <label>Password</label>
                            <Password
                                v-model="form.password"
                                :feedback="isRegister"
                                toggle-mask
                                :placeholder="isRegister ? 'At least 8 characters' : 'Your password'"
                                :autocomplete="isRegister ? 'new-password' : 'current-password'"
                                prompt-label="Add a password"
                                weak-label="Keep going"
                                medium-label="Good"
                                strong-label="Strong"
                                input-class="auth-password-input"
                                fluid
                            />
                        </div>

                        <div v-if="isRegister" class="field">
                            <label>Confirm password</label>
                            <Password
                                v-model="form.password_confirmation"
                                toggle-mask
                                :feedback="false"
                                placeholder="Repeat it"
                                autocomplete="new-password"
                                input-class="auth-password-input"
                                fluid
                            />
                        </div>
                    </template>

                    <Message v-if="notice" severity="success" :closable="false">{{ notice }}</Message>
                    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

                    <Button
                        type="submit"
                        :label="verifying ? 'Verify and open workspace' : (isRegister ? 'Send verification code' : 'Log in')"
                        :icon="verifying ? 'pi pi-shield' : (isRegister ? 'pi pi-envelope' : 'pi pi-sign-in')"
                        :loading="busy"
                        :disabled="verifying && String(otp || '').replace(/\D/g, '').length !== 6"
                        fluid
                        class="auth-submit"
                    />

                    <div v-if="verifying" class="otp-actions">
                        <Button type="button" label="Use a different email" text severity="secondary" @click="step = 'form'" />
                        <Button type="button" label="Resend code" text :loading="resending" @click="resend" />
                    </div>
                </form>

                <div v-if="isDev && !verifying" class="demo-box">
                    <div class="demo-head"><span><i class="pi pi-sparkles" /> Demo access</span><Tag value="Dev only" severity="secondary" /></div>
                    <div class="demo-buttons">
                        <button type="button" @click="demo('ada@northwind.test')"><Avatar label="A" shape="circle" style="background:#e23d2b;color:#fff" /> <span>Ada · Owner</span></button>
                        <button type="button" @click="demo('lin@northwind.test')"><Avatar label="L" shape="circle" style="background:#1d4e89;color:#fff" /> <span>Lin · Admin</span></button>
                        <button type="button" @click="demo('grace@northwind.test')"><Avatar label="G" shape="circle" style="background:#0f6e62;color:#fff" /> <span>Grace · Member</span></button>
                    </div>
                </div>

                <p class="auth-legal">By continuing you agree to keep the workspace a decent place to work.</p>
            </div>
        </section>
    </div>
</template>
