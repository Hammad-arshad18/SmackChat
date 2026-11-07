<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { useUiStore } from '@/stores/ui';
import { attachmentIdFromUrl, fetchAttachmentBlobUrl } from '@/lib/attachments';

const ui = useUiStore();
const index = ref(0);
const src = ref('');
const loading = ref(false);

const visible = computed({
    get: () => Boolean(ui.mediaPreview),
    set: (value) => {
        if (!value) {
            close();
        }
    },
});
const items = computed(() => ui.mediaPreview?.items || []);
const current = computed(() => items.value[index.value] || null);
const canPrev = computed(() => index.value > 0);
const canNext = computed(() => index.value < items.value.length - 1);

watch(visible, (visible) => {
    if (visible) {
        index.value = ui.mediaPreview?.index ?? 0;
        loadCurrent();
    } else {
        src.value = '';
    }
});

watch(index, () => {
    if (visible.value) {
        loadCurrent();
    }
});

async function loadCurrent() {
    const item = current.value;
    if (!item) {
        src.value = '';
        return;
    }

    loading.value = true;
    const id = attachmentIdFromUrl(item.url);
    const blob = id ? await fetchAttachmentBlobUrl(id) : null;
    src.value = blob || item.url;
    loading.value = false;
}

function close() {
    ui.closeMediaPreview();
}

function prev() {
    if (canPrev.value) index.value -= 1;
}

function next() {
    if (canNext.value) index.value += 1;
}

function download() {
    const item = current.value;
    if (!item || !src.value) return;

    const link = document.createElement('a');
    link.href = src.value;
    link.download = item.name || 'attachment';
    link.rel = 'noreferrer';
    link.click();
}

function onKey(event) {
    if (!visible.value) return;

    if (event.key === 'Escape') {
        close();
    } else if (event.key === 'ArrowLeft') {
        prev();
    } else if (event.key === 'ArrowRight') {
        next();
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        dismissable-mask
        class="media-preview-dialog"
        :style="{ width: 'min(96vw, 1080px)', maxWidth: '96vw' }"
    >
        <template #header>
            <div class="media-preview-head">
                <div>
                    <strong>{{ current?.name || 'Attachment' }}</strong>
                    <small v-if="items.length > 1">{{ index + 1 }} of {{ items.length }}</small>
                </div>
                <div class="media-preview-actions">
                    <Button icon="pi pi-download" text rounded aria-label="Download" @click="download" />
                    <Button icon="pi pi-times" text rounded aria-label="Close" @click="close" />
                </div>
            </div>
        </template>

        <div v-if="current" class="media-preview-body">
            <Button
                v-if="canPrev"
                class="media-preview-nav prev"
                icon="pi pi-chevron-left"
                rounded
                text
                aria-label="Previous"
                @click="prev"
            />
            <Button
                v-if="canNext"
                class="media-preview-nav next"
                icon="pi pi-chevron-right"
                rounded
                text
                aria-label="Next"
                @click="next"
            />

            <div v-if="loading" class="media-preview-loading">
                <i class="pi pi-spin pi-spinner" />
                <span>Loading preview…</span>
            </div>

            <template v-else>
                <img
                    v-if="current.kind === 'image' && src"
                    :src="src"
                    :alt="current.name"
                    class="media-preview-image"
                />
                <video
                    v-else-if="current.kind === 'video' && src"
                    :src="src"
                    class="media-preview-video"
                    controls
                    autoplay
                />
                <iframe
                    v-else-if="current.extension === 'pdf' && src"
                    :src="src"
                    class="media-preview-pdf"
                    title="PDF preview"
                />
                <div v-else class="media-preview-fallback">
                    <i class="pi pi-file" />
                    <p>{{ current.name }}</p>
                    <Button label="Download file" icon="pi pi-download" @click="download" />
                </div>
            </template>
        </div>
    </Dialog>
</template>
