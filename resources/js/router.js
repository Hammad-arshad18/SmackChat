import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', redirect: '/app' },
        { path: '/login', component: () => import('@/views/AuthView.vue'), meta: { guest: true } },
        { path: '/invite/:token', component: () => import('@/views/InviteView.vue') },
        { path: '/app', component: () => import('@/views/RedirectHome.vue'), meta: { auth: true } },
        {
            path: '/w/:slug',
            component: () => import('@/views/Shell.vue'),
            meta: { auth: true },
            children: [
                { path: '', name: 'lounge', component: () => import('@/views/LoungeView.vue') },
                { path: 'c/:channel', name: 'channel', component: () => import('@/views/ChannelView.vue'), props: true },
                { path: 'attendance', component: () => import('@/views/AttendanceView.vue') },
                { path: 'hours', component: () => import('@/views/HoursView.vue') },
                { path: 'members', component: () => import('@/views/MembersView.vue') },
                { path: 'settings', component: () => import('@/views/SettingsView.vue') },
                { path: 'billing', component: () => import('@/views/BillingView.vue') },
            ],
        },
    ],
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (!auth.ready) {
        await auth.fetchMe();
    }

    if (to.meta.auth && !auth.user) {
        return { path: '/login', query: { next: to.fullPath } };
    }

    if (to.meta.guest && auth.user) {
        return '/app';
    }

    return true;
});

export default router;
