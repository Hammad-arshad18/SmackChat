<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Plays one person's voice. It is kept apart from the video tiles on purpose:
 * tiles are reordered, hidden, and remounted as people pin and share, and a
 * media element that is moved in the page stops playing.
 */
const props = defineProps({
    stream: { type: Object, default: null },
});

const emit = defineEmits(['blocked', 'playing']);

const el = ref(null);
const bound = new WeakSet();

function play() {
    const node = el.value;

    if (!node?.srcObject) {
        return;
    }

    node.play().then(() => {
        emit('playing');
    }).catch((error) => {
        // The browser wants a tap before it plays sound. The dock shows a
        // "Tap to hear" button for that.
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

    stream?.getAudioTracks?.().forEach((track) => {
        if (!bound.has(track)) {
            bound.add(track);
            track.addEventListener('unmute', play);
        }
    });

    play();
}

watch(() => props.stream, bind);

onMounted(() => bind(props.stream));

onBeforeUnmount(() => {
    if (el.value) {
        el.value.srcObject = null;
    }
});
</script>

<template>
    <audio ref="el" class="call-audio" autoplay playsinline />
</template>
