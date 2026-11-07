<script setup>
import MediaPreview from '@/components/MediaPreview.vue';
</script>

<template>
    <RouterView />
    <MediaPreview />
    <Toast position="top-right" />
    <ConfirmDialog />
</template>
