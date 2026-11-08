import { defineStore } from 'pinia';
import api, { ensureCsrf } from '@/api';
import { disablePush } from '@/push';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        ready: false,
    }),
    actions: {
        async fetchMe() {
            try {
                const { data } = await api.get('/me');
                this.user = data.user;
            } catch {
                this.user = null;
            } finally {
                this.ready = true;
            }
        },
        async register(payload) {
            await ensureCsrf();
            const { data } = await api.post('/register', payload);

            return data;
        },
        async verifyRegistration(payload) {
            await ensureCsrf();
            const { data } = await api.post('/register/verify', payload);
            this.user = data.user;
            this.ready = true;

            return data.workspace_slug;
        },
        async resendRegistrationCode(email) {
            await ensureCsrf();
            const { data } = await api.post('/register/resend', { email });

            return data;
        },
        async login(payload) {
            await ensureCsrf();
            const { data } = await api.post('/login', payload);
            this.user = data.user;
            this.ready = true;
        },
        async updateProfile(payload) {
            const { data } = await api.patch('/me', payload);
            this.user = data.user;

            return data.user;
        },
        async logout() {
            await disablePush().catch(() => {});
            await api.post('/logout');
            this.user = null;
        },
        adopt(user) {
            this.user = user;
            this.ready = true;
        },
    },
});
