<script setup>
import CallDock from '@/components/CallDock.vue';
import MediaPreview from '@/components/MediaPreview.vue';
</script>

<template>
    <RouterView />
    <MediaPreview />
    <CallDock />
    <Toast position="top-right" />
    <ConfirmDialog />
</template>
