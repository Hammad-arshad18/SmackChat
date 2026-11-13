<script setup>
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import BrandMark from '@/components/BrandMark.vue';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const router = useRouter();

onMounted(async () => {
    if (!auth.ready) {
        await auth.fetchMe();
    }

    const slug = auth.user?.current_workspace?.slug || auth.user?.workspaces?.[0]?.slug;
    await router.replace(slug ? `/w/${slug}` : '/login');
});
</script>

<template>
    <div class="boot-screen">
        <BrandMark size="boot" />
        <strong>Opening your workspace</strong>
        <span>Finding the conversation…</span>
    </div>
</template>
