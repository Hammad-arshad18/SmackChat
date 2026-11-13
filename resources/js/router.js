import { createRouter, createWebHistory } from 'vue-router';
import { isStandalone } from '@/push';
import { useAuthStore } from '@/stores/auth';
import { applySeo, DEFAULT_DESCRIPTION, DEFAULT_TITLE } from '@/seo';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/',
            component: () => import('@/views/LandingView.vue'),
            meta: {
                title: 'SmackChat — your team\'s day, in one place',
                description: 'Office chat for one company: encrypted channels and DMs, a floor clock that files timesheets, attendance heatmaps, and Forever archive when history must last.',
            },
        },
        {
            path: '/login',
            component: () => import('@/views/AuthView.vue'),
            meta: {
                guest: true,
                title: 'Sign in — SmackChat',
                description: 'Sign in to SmackChat to message your team, track attendance, and see who\'s in today.',
            },
        },
        {
            path: '/invite/:token',
            component: () => import('@/views/InviteView.vue'),
            meta: {
                title: 'Workspace invite — SmackChat',
                description: 'Accept your SmackChat workspace invite and join your team.',
                noindex: true,
            },
        },
        {
            path: '/app',
            component: () => import('@/views/RedirectHome.vue'),
            meta: { auth: true, noindex: true, title: 'SmackChat' },
        },
        {
            path: '/w/:slug',
            component: () => import('@/views/Shell.vue'),
            meta: { auth: true, noindex: true },
            children: [
                {
                    path: '',
                    name: 'lounge',
                    component: () => import('@/views/LoungeView.vue'),
                    meta: { title: 'Today — SmackChat' },
                },
                {
                    path: 'c/:channel',
                    name: 'channel',
                    component: () => import('@/views/ChannelView.vue'),
                    props: true,
                    meta: { title: 'Channel — SmackChat' },
                },
                {
                    path: 'attendance',
                    component: () => import('@/views/AttendanceView.vue'),
                    meta: { title: 'Attendance — SmackChat' },
                },
                {
                    path: 'hours',
                    component: () => import('@/views/HoursView.vue'),
                    meta: { title: 'Schedule — SmackChat' },
                },
                {
                    path: 'members',
                    component: () => import('@/views/MembersView.vue'),
                    meta: { title: 'People — SmackChat' },
                },
                {
                    path: 'settings',
                    component: () => import('@/views/SettingsView.vue'),
                    meta: { title: 'Settings — SmackChat' },
                },
                {
                    path: 'billing',
                    component: () => import('@/views/BillingView.vue'),
                    meta: { title: 'Plans — SmackChat' },
                },
            ],
        },
    ],
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    // Installed PWAs often keep an old start_url ("/"). Never show marketing there.
    if (to.path === '/' && isStandalone()) {
        return '/app';
    }

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

router.afterEach((to) => {
    const nearestWithMeta = [...to.matched].reverse().find((record) => record.meta?.title || record.meta?.noindex);
    const noindex = to.matched.some((record) => record.meta?.noindex);
    applySeo({
        title: nearestWithMeta?.meta?.title || DEFAULT_TITLE,
        description: nearestWithMeta?.meta?.description || DEFAULT_DESCRIPTION,
        path: to.fullPath,
        noindex,
    });
});

export default router;
