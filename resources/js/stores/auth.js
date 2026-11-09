import { defineStore } from 'pinia';
import api, { ensureCsrf } from '@/api';
import { ensureDevice, forgetEncryption } from '@/e2e';
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
                if (data.user?.id) {
                    await ensureDevice(data.user.id).catch(() => {});
                }
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
            if (data.user?.id) {
                await ensureDevice(data.user.id).catch(() => {});
            }

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
            if (data.user?.id) {
                await ensureDevice(data.user.id).catch(() => {});
            }
        },
        async updateProfile(payload) {
            const { data } = await api.patch('/me', payload);
            this.user = data.user;

            return data.user;
        },
        async updateAvatar(file) {
            const body = new FormData();
            body.append('avatar', file);
            const { data } = await api.post('/me/avatar', body);
            this.user = data.user;

            return data.user;
        },
        async removeAvatar() {
            const { data } = await api.delete('/me/avatar');
            this.user = data.user;

            return data.user;
        },
        async logout() {
            await disablePush().catch(() => {});
            await api.post('/logout');
            forgetEncryption();
            this.user = null;
        },
        adopt(user) {
            this.user = user;
            this.ready = true;
            if (user?.id) {
                ensureDevice(user.id).catch(() => {});
            }
        },
    },
});
