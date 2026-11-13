<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useUiStore } from '@/stores/ui';
import {
    attachmentFrameStyle,
    attachmentIdFromUrl,
    cachedAttachmentUrl,
    fetchAttachmentBlobUrl,
    releaseAttachment,
    retainAttachment,
} from '@/lib/attachments';

const props = defineProps({
    items: { type: Array, default: () => [] },
    mine: { type: Boolean, default: false },
});

const ui = useUiStore();
const sources = reactive({});
const loaded = reactive({});
const failed = reactive({});
const retained = ref(new Set());

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

function idFor(item) {
    return item?.id || attachmentIdFromUrl(item?.url);
}

function srcFor(item) {
    const id = idFor(item);

    return (id && sources[id]) || cachedAttachmentUrl(id) || '';
}

function isReady(item) {
    const id = idFor(item);

    return Boolean(id && loaded[id] && srcFor(item));
}

function markLoaded(item) {
    const id = idFor(item);
    if (id) {
        loaded[id] = true;
    }
}

function markFailed(item) {
    const id = idFor(item);
    if (id) {
        failed[id] = true;
    }
}

async function markImageReady(id, url) {
    if (!id || !url || loaded[id]) {
        return;
    }

    try {
        const probe = new Image();
        probe.src = url;
        await probe.decode();
        loaded[id] = true;
    } catch {
        /* @load / @error handlers finish this */
    }
}

async function hydrate(item) {
    const id = idFor(item);
    if (!id) {
        return;
    }

    if (!retained.value.has(id)) {
        retainAttachment(id);
        retained.value.add(id);
    }

    if (!sources[id]) {
        const warm = cachedAttachmentUrl(id);
        if (warm) {
            sources[id] = warm;
        }
    }

    if (!sources[id]) {
        const url = await fetchAttachmentBlobUrl(id);
        if (!url) {
            failed[id] = true;
            return;
        }
        sources[id] = url;
    }

    if (item.kind === 'image') {
        await markImageReady(id, sources[id]);
    }
}

function openPreview(item) {
    const previewable = props.items.filter((entry) => ['image', 'video'].includes(entry.kind) || entry.extension === 'pdf');
    const previewIndex = previewable.findIndex((entry) => entry.id === item.id);

    if (previewIndex === -1) {
        const href = srcFor(item) || item.url;
        if (href) {
            window.open(href, '_blank', 'noopener,noreferrer');
        }
        return;
    }

    ui.openMediaPreview({
        items: previewable,
        index: previewIndex,
    });
}

function openFile(item) {
    const href = srcFor(item) || item.url;
    if (href) {
        window.open(href, '_blank', 'noopener,noreferrer');
    }
}

const mediaItems = computed(() => props.items || []);

watch(mediaItems, (items) => {
    items.forEach((item) => {
        hydrate(item);
    });
}, { immediate: true, deep: true });

onBeforeUnmount(() => {
    retained.value.forEach((id) => releaseAttachment(id));
    retained.value = new Set();
});
</script>

<template>
    <div v-if="items?.length" class="msg-attachments" :class="{ mine }">
        <template v-for="(item, index) in items" :key="item.id || `${item.url}-${index}`">
            <button
                v-if="item.kind === 'image'"
                type="button"
                class="msg-attach-image"
                :class="{ ready: isReady(item), failed: failed[idFor(item)] }"
                :style="attachmentFrameStyle(item)"
                :aria-label="`Preview ${item.name}`"
                :disabled="!srcFor(item) && !failed[idFor(item)]"
                @click="openPreview(item)"
            >
                <span v-if="!isReady(item)" class="attach-skeleton" aria-hidden="true" />
                <img
                    v-if="srcFor(item)"
                    :src="srcFor(item)"
                    :alt="item.name"
                    loading="lazy"
                    decoding="async"
                    @load="markLoaded(item)"
                    @error="markFailed(item)"
                />
                <span v-if="isReady(item)" class="attach-overlay"><i class="pi pi-search-plus" /></span>
                <span v-else-if="failed[idFor(item)]" class="attach-fallback">Could not load image</span>
            </button>
            <button
                v-else-if="item.kind === 'video'"
                type="button"
                class="msg-attach-video"
                :class="{ ready: isReady(item), failed: failed[idFor(item)] }"
                :style="attachmentFrameStyle(item)"
                :aria-label="`Preview ${item.name}`"
                :disabled="!srcFor(item) && !failed[idFor(item)]"
                @click="openPreview(item)"
            >
                <span v-if="!isReady(item)" class="attach-skeleton" aria-hidden="true" />
                <video
                    v-if="srcFor(item)"
                    :src="srcFor(item)"
                    muted
                    preload="metadata"
                    playsinline
                    @loadedmetadata="markLoaded(item)"
                    @loadeddata="markLoaded(item)"
                    @error="markFailed(item)"
                />
                <span v-if="isReady(item)" class="attach-overlay video"><i class="pi pi-play" /></span>
                <span v-else-if="failed[idFor(item)]" class="attach-fallback">Could not load video</span>
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
