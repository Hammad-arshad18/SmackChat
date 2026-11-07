<script setup>
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const router = useRouter();

onMounted(() => {
    const slug = auth.user?.current_workspace?.slug || auth.user?.workspaces?.[0]?.slug;
    router.replace(slug ? `/w/${slug}` : '/login');
});
</script>

<template>
    <div class="boot-screen">
        <div class="boot-mark"><i class="pi pi-comment" /></div>
        <strong>Opening your workspace</strong>
        <span>Finding the conversation…</span>
    </div>
</template>
