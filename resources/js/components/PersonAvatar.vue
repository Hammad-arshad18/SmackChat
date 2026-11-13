<script setup>
import { computed } from 'vue';

const props = defineProps({
    person: { type: Object, default: null },
    name: { type: String, default: '' },
    color: { type: String, default: '' },
    image: { type: String, default: '' },
    size: { type: String, default: undefined },
});

const label = computed(() => (props.person?.name || props.name || '?').slice(0, 1).toUpperCase());
const picture = computed(() => props.person?.avatar_url || props.image || '');
const background = computed(() => props.person?.avatar_color || props.color || '#0e7c66');
</script>

<template>
    <Avatar
        :image="picture || undefined"
        :label="picture ? undefined : label"
        shape="circle"
        :size="size"
        :pt="picture ? { image: { loading: 'lazy', decoding: 'async' } } : undefined"
        :style="picture ? undefined : { background, color: '#fff' }"
        :aria-label="person?.name || name || 'Profile photo'"
    />
</template>
