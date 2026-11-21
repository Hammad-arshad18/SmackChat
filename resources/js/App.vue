<script setup>
import { Toaster } from 'dynaura/vue';
import CallDock from '@/components/CallDock.vue';
import MediaPreview from '@/components/MediaPreview.vue';
import { islandTheme } from '@/toast';
</script>

<template>
    <RouterView />
    <MediaPreview />
    <CallDock />
    <Toaster position="top" :offset="16" :theme="islandTheme" queue :max-queue="3" :haptics="false" />
    <ConfirmDialog />
</template>
