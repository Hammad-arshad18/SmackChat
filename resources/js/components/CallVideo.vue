<script setup>
import { onMounted, ref, watch } from 'vue';

const props = defineProps({
    stream: { type: Object, default: null },
    muted: { type: Boolean, default: false },
    mirror: { type: Boolean, default: false },
});

const el = ref(null);

function bind(stream) {
    if (el.value && el.value.srcObject !== (stream || null)) {
        el.value.srcObject = stream || null;
    }
}

watch(() => props.stream, bind);

onMounted(() => bind(props.stream));
</script>

<template>
    <video ref="el" class="call-video" autoplay playsinline :muted="muted" :class="{ mirror }" />
</template>
