<script setup>
import { onMounted, ref, watch } from 'vue';

const props = defineProps({
    stream: { type: Object, default: null },
    muted: { type: Boolean, default: false },
    mirror: { type: Boolean, default: false },
    fit: { type: String, default: 'cover' },
});

const emit = defineEmits(['blocked', 'playing']);

const el = ref(null);

function play(node) {
    if (!node?.srcObject) {
        return;
    }

    node.play().then(() => {
        emit('playing');
    }).catch((error) => {
        if (error?.name !== 'AbortError') {
            emit('blocked');
        }
    });
}

function bind(stream) {
    const node = el.value;

    if (!node) {
        return;
    }

    if (node.srcObject !== (stream || null)) {
        node.srcObject = stream || null;
    }

    stream?.getTracks?.().forEach((track) => {
        track.onunmute = () => play(node);
    });

    play(node);
}

watch(() => props.stream, bind);

onMounted(() => bind(props.stream));
</script>

<template>
    <video ref="el" class="call-video" autoplay playsinline :muted="muted" :class="{ mirror, contain: fit === 'contain' }" />
</template>
