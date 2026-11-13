<script setup>
defineProps({
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md', 'lg', 'boot'].includes(value),
    },
    alt: {
        type: String,
        default: 'SmackChat',
    },
});

// Public asset — dynamic binding so Vite leaves /public paths alone.
const markUrl = '/brand/logo-mark.png';
</script>

<template>
    <img
        class="brand-mark"
        :class="`is-${size}`"
        :src="markUrl"
        :alt="alt"
        width="128"
        height="128"
        decoding="async"
    >
</template>
