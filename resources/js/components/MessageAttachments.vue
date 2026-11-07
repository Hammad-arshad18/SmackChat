<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { useUiStore } from '@/stores/ui';
import { attachmentIdFromUrl, fetchAttachmentBlobUrl, releaseAttachmentBlob } from '@/lib/attachments';

const props = defineProps({
    items: { type: Array, default: () => [] },
    mine: { type: Boolean, default: false },
});

const ui = useUiStore();
const sources = ref({});

function sizeLabel(bytes) {
    const value = Number(bytes) || 0;
    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

function iconFor(item) {
    if (item.kind === 'image') return 'pi pi-image';
    if (item.kind === 'video') return 'pi pi-video';
    if (item.extension === 'pdf') return 'pi pi-file-pdf';
    if (['zip'].includes(item.extension)) return 'pi pi-box';
    return 'pi pi-file';
}

function srcFor(item) {
    const id = attachmentIdFromUrl(item.url);

    return (id && sources.value[id]) || item.url;
}

async function hydrate(item) {
    const id = attachmentIdFromUrl(item.url);
    if (!id || sources.value[id]) {
        return;
    }

    const url = await fetchAttachmentBlobUrl(id);
    if (url) {
        sources.value[id] = url;
    }
}

function openPreview(item, index) {
    const previewable = props.items.filter((entry) => ['image', 'video'].includes(entry.kind) || entry.extension === 'pdf');
    const previewIndex = previewable.findIndex((entry) => entry.id === item.id);

    if (previewIndex === -1) {
        window.open(srcFor(item), '_blank', 'noopener,noreferrer');
        return;
    }

    ui.openMediaPreview({
        items: previewable,
        index: previewIndex,
    });
}

function openFile(item) {
    const id = attachmentIdFromUrl(item.url);
    const href = id && sources.value[id] ? sources.value[id] : item.url;
    window.open(href, '_blank', 'noopener,noreferrer');
}

watch(() => props.items, (items) => {
    items.forEach(hydrate);
}, { immediate: true, deep: true });

onBeforeUnmount(() => {
    props.items.forEach((item) => {
        const id = attachmentIdFromUrl(item.url);
        if (id) {
            releaseAttachmentBlob(id);
        }
    });
});
</script>

<template>
    <div v-if="items?.length" class="msg-attachments" :class="{ mine }">
        <template v-for="(item, index) in items" :key="item.id">
            <button
                v-if="item.kind === 'image'"
                type="button"
                class="msg-attach-image"
                :aria-label="`Preview ${item.name}`"
                @click="openPreview(item, index)"
            >
                <img :src="srcFor(item)" :alt="item.name" loading="lazy" />
                <span class="attach-overlay"><i class="pi pi-search-plus" /></span>
            </button>
            <button
                v-else-if="item.kind === 'video'"
                type="button"
                class="msg-attach-video"
                :aria-label="`Preview ${item.name}`"
                @click="openPreview(item, index)"
            >
                <video :src="srcFor(item)" muted preload="metadata" />
                <span class="attach-overlay video"><i class="pi pi-play" /></span>
            </button>
            <button
                v-else
                type="button"
                class="msg-attach-file"
                @click="openFile(item)"
            >
                <i :class="iconFor(item)" />
                <span>
                    <strong>{{ item.name }}</strong>
                    <small>{{ sizeLabel(item.size) }} · {{ (item.extension || 'file').toUpperCase() }}</small>
                </span>
                <i class="pi pi-external-link" />
            </button>
        </template>
    </div>
</template>
