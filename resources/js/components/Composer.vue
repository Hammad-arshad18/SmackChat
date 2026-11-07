<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import Mention from '@tiptap/extension-mention';
import { useToast } from 'primevue/usetoast';
import api, { errorText } from '@/api';
import { useOfficeStore } from '@/stores/office';

const props = defineProps({
    placeholder: { type: String, default: 'Write a message' },
    members: { type: Array, default: () => [] },
    initial: { type: Object, default: null },
    submitLabel: { type: String, default: 'Send' },
    draftKey: { type: String, default: '' },
    compact: { type: Boolean, default: false },
    channel: { type: String, default: '' },
    allowAttachments: { type: Boolean, default: true },
});

const emit = defineEmits(['send', 'typing']);

const office = useOfficeStore();
const toast = useToast();
const emojis = ['👍', '❤️', '😄', '🎉', '✅', '👀', '🚀', '🔥', '✨', '🙏', '💡', '☕', '🎯', '🙌', '😅', '🫡', '🧠', '📌', '💬', '⚡'];
const emojiPop = ref(null);
const linkPop = ref(null);
const linkUrl = ref('');
const mentionOpen = ref(false);
const mentionItems = ref([]);
const mentionIndex = ref(0);
const charCount = ref(0);
const fileInput = ref(null);
const attachments = ref([]);
const uploading = ref(false);
const dragOver = ref(false);
let mentionCommand = null;
let typingAt = 0;

const acceptTypes = '.jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.mov,.pdf,.txt,.md,.csv,.log,.doc,.docx,.xls,.xlsx,.zip';
const maxFiles = 10;
const maxBytes = 20 * 1024 * 1024;

function draftContent() {
    if (props.initial) {
        return props.initial;
    }

    if (!props.draftKey) {
        return '';
    }

    try {
        const saved = localStorage.getItem(`smack.draft.${props.draftKey}`);

        return saved ? JSON.parse(saved) : '';
    } catch {
        return '';
    }
}

function saveDraft(json) {
    if (!props.draftKey || props.initial) {
        return;
    }

    try {
        const text = editor.value?.getText().trim();

        if (text) {
            localStorage.setItem(`smack.draft.${props.draftKey}`, JSON.stringify(json));
        } else {
            localStorage.removeItem(`smack.draft.${props.draftKey}`);
        }
    } catch {
        /* storage can be full or blocked */
    }
}

const editor = useEditor({
    content: draftContent(),
    extensions: [
        StarterKit.configure({ heading: false }),
        Underline,
        Link.configure({ openOnClick: false, autolink: true }),
        Placeholder.configure({ placeholder: props.placeholder }),
        Mention.configure({
            HTMLAttributes: { class: 'mention' },
            suggestion: {
                items: ({ query }) => props.members
                    .filter((member) => member.name.toLowerCase().includes(query.toLowerCase()))
                    .slice(0, 6),
                render: () => ({
                    onStart: (suggestion) => openMention(suggestion),
                    onUpdate: (suggestion) => openMention(suggestion),
                    onKeyDown: ({ event }) => {
                        if (!mentionItems.value.length) {
                            return false;
                        }

                        if (event.key === 'ArrowDown') {
                            mentionIndex.value = (mentionIndex.value + 1) % mentionItems.value.length;
                            return true;
                        }

                        if (event.key === 'ArrowUp') {
                            mentionIndex.value = (mentionIndex.value + mentionItems.value.length - 1) % mentionItems.value.length;
                            return true;
                        }

                        if (event.key === 'Enter' || event.key === 'Tab') {
                            choose(mentionIndex.value);
                            return true;
                        }

                        if (event.key === 'Escape') {
                            mentionOpen.value = false;
                            return true;
                        }

                        return false;
                    },
                    onExit: () => {
                        mentionOpen.value = false;
                        mentionCommand = null;
                    },
                }),
            },
        }),
    ],
    editorProps: {
        handleKeyDown(view, event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                if (mentionOpen.value) {
                    return false;
                }

                event.preventDefault();
                submit();
                return true;
            }

            return false;
        },
        handlePaste(view, event) {
            const files = [...(event.clipboardData?.files || [])];
            if (!files.length || !canAttach.value) {
                return false;
            }

            event.preventDefault();
            queueFiles(files);
            return true;
        },
        handleDrop(view, event) {
            const files = [...(event.dataTransfer?.files || [])];
            if (!files.length || !canAttach.value) {
                return false;
            }

            event.preventDefault();
            queueFiles(files);
            return true;
        },
    },
    onUpdate: ({ editor: current }) => {
        charCount.value = current.getText().length;
        saveDraft(current.getJSON());

        const now = Date.now();
        if (now - typingAt > 2000) {
            typingAt = now;
            emit('typing');
        }
    },
});

onMounted(() => {
    charCount.value = editor.value?.getText().length || 0;
    if (editor.value?.isEmpty && props.initial) {
        charCount.value = 0;
    }
});

const canAttach = computed(() => props.allowAttachments && !!props.channel && !props.initial);
const isEmpty = computed(() => charCount.value === 0 && !attachments.value.length);
const canSend = computed(() => !isEmpty.value && !uploading.value && charCount.value <= 8000);

function openMention(suggestion) {
    mentionOpen.value = true;
    mentionItems.value = suggestion.items;
    mentionCommand = suggestion.command;
    mentionIndex.value = 0;
}

function choose(index) {
    const item = mentionItems.value[index];
    if (!item || !mentionCommand) {
        return;
    }

    mentionCommand({ id: String(item.id), label: item.name });
}

function run(action) {
    const chain = editor.value?.chain().focus();
    chain?.[action]().run();
}

function toggleEmoji(event) {
    emojiPop.value.toggle(event);
}

function toggleLink(event) {
    linkUrl.value = editor.value?.getAttributes('link').href || '';
    linkPop.value.toggle(event);
}

function applyLink() {
    const href = linkUrl.value.trim();
    if (!href) {
        editor.value?.chain().focus().unsetLink().run();
    } else {
        const safe = /^(https?:|mailto:)/i.test(href) ? href : `https://${href}`;
        editor.value?.chain().focus().extendMarkRange('link').setLink({ href: safe }).run();
    }
    linkPop.value?.hide?.();
}

function insertEmoji(emoji) {
    editor.value?.chain().focus().insertContent(emoji).run();
    emojiPop.value?.hide?.();
}

function insertText(text) {
    editor.value?.chain().focus().insertContent(text).run();
}

function focus() {
    editor.value?.commands.focus();
}

function clear() {
    editor.value?.commands.clearContent(true);
    charCount.value = 0;
    attachments.value.forEach((item) => {
        if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);
    });
    attachments.value = [];
    if (props.draftKey) {
        localStorage.removeItem(`smack.draft.${props.draftKey}`);
    }
}

function pickFiles() {
    fileInput.value?.click();
}

function onFilesSelected(event) {
    const files = [...(event.target.files || [])];
    event.target.value = '';
    queueFiles(files);
}

function onDropZone(event) {
    dragOver.value = false;
    if (!canAttach.value) return;
    const files = [...(event.dataTransfer?.files || [])];
    queueFiles(files);
}

async function queueFiles(files) {
    if (!canAttach.value || !files.length) {
        return;
    }

    const remaining = maxFiles - attachments.value.length;
    if (remaining <= 0) {
        toast.add({ severity: 'warn', summary: 'Attachments', detail: `You can attach up to ${maxFiles} files.`, life: 2800 });
        return;
    }

    const batch = files.slice(0, remaining);
    for (const file of batch) {
        if (file.size > maxBytes) {
            toast.add({ severity: 'warn', summary: file.name, detail: 'Files must be 20 MB or smaller.', life: 3200 });
            continue;
        }

        await uploadFile(file);
    }
}

async function uploadFile(file) {
    uploading.value = true;
    const localId = `local-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const previewUrl = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
    attachments.value.push({
        localId,
        id: null,
        name: file.name,
        size: file.size,
        kind: file.type.startsWith('image/') ? 'image' : (file.type.startsWith('video/') ? 'video' : 'file'),
        previewUrl,
        uploading: true,
    });

    try {
        const form = new FormData();
        form.append('file', file);
        const { data } = await api.post(
            `/workspaces/${office.slug}/channels/${props.channel}/attachments`,
            form,
        );

        const index = attachments.value.findIndex((item) => item.localId === localId);
        if (index !== -1) {
            attachments.value[index] = {
                ...attachments.value[index],
                ...data.attachment,
                uploading: false,
            };
        }
    } catch (error) {
        attachments.value = attachments.value.filter((item) => item.localId !== localId);
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        toast.add({ severity: 'error', summary: 'Upload failed', detail: errorText(error, 'Could not upload that file.'), life: 3500 });
    } finally {
        uploading.value = attachments.value.some((item) => item.uploading);
    }
}

function removeAttachment(item) {
    if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);
    attachments.value = attachments.value.filter((entry) => entry.localId !== item.localId && entry.id !== item.id);
}

function sizeLabel(bytes) {
    const value = Number(bytes) || 0;
    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

function emptyDocument() {
    return { type: 'doc', content: [{ type: 'paragraph' }] };
}

function submit() {
    if (!editor.value || !canSend.value) {
        return;
    }

    if (attachments.value.some((item) => item.uploading || !item.id)) {
        toast.add({ severity: 'warn', summary: 'Still uploading', detail: 'Wait for attachments to finish.', life: 2500 });
        return;
    }

    const text = editor.value.getText().trim();
    const document = text ? editor.value.getJSON() : emptyDocument();
    const attachmentIds = attachments.value.map((item) => item.id).filter(Boolean);

    emit('send', { document, attachment_ids: attachmentIds });
    clear();
}

defineExpose({ insertText, focus, clear });

onBeforeUnmount(() => {
    attachments.value.forEach((item) => {
        if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);
    });
    editor.value?.destroy();
});
</script>

<template>
    <div
        class="composer"
        :class="{ compact, 'is-dragover': dragOver }"
        @dragenter.prevent="canAttach && (dragOver = true)"
        @dragover.prevent="canAttach && (dragOver = true)"
        @dragleave.prevent="dragOver = false"
        @drop.prevent="onDropZone"
    >
        <input
            ref="fileInput"
            type="file"
            class="sr-only"
            multiple
            :accept="acceptTypes"
            @change="onFilesSelected"
        />

        <div v-if="mentionOpen && mentionItems.length" class="mention-menu">
            <button
                v-for="(item, index) in mentionItems"
                :key="item.id"
                type="button"
                :class="{ active: index === mentionIndex }"
                @mousedown.prevent="choose(index)"
            >
                <Avatar :label="item.name.slice(0, 1)" shape="circle" :style="{ background: item.avatar_color, color: '#fff' }" />
                <span>{{ item.name }}</span>
                <small>{{ item.title || 'Teammate' }}</small>
            </button>
        </div>

        <Popover ref="emojiPop">
            <div class="emoji-grid">
                <button v-for="emoji in emojis" :key="emoji" type="button" @click="insertEmoji(emoji)">{{ emoji }}</button>
            </div>
        </Popover>

        <Popover ref="linkPop">
            <form class="link-form" @submit.prevent="applyLink">
                <p class="form-label">Link a URL</p>
                <InputText v-model="linkUrl" placeholder="https://example.com" fluid autofocus />
                <div class="link-actions">
                    <Button label="Remove" text severity="secondary" size="small" type="button" @click="linkUrl = ''; applyLink()" />
                    <Button label="Apply" size="small" type="submit" />
                </div>
            </form>
        </Popover>

        <div v-if="attachments.length" class="composer-attachments">
            <div
                v-for="item in attachments"
                :key="item.localId || item.id"
                class="composer-attach-chip"
                :class="{ uploading: item.uploading }"
            >
                <img v-if="item.previewUrl || item.kind === 'image'" :src="item.previewUrl || item.url" :alt="item.name" />
                <i v-else :class="item.kind === 'video' ? 'pi pi-video' : 'pi pi-file'" />
                <span>
                    <strong>{{ item.name }}</strong>
                    <small>{{ item.uploading ? 'Uploading…' : sizeLabel(item.size) }}</small>
                </span>
                <button type="button" aria-label="Remove attachment" @click="removeAttachment(item)">
                    <i class="pi pi-times" />
                </button>
            </div>
        </div>

        <div class="composer-shell">
            <div class="composer-toolbar">
                <Button
                    icon="pi pi-face-smile"
                    text
                    rounded
                    size="small"
                    aria-label="Emoji"
                    v-tooltip.top="'Emoji'"
                    @click="toggleEmoji"
                />
                <Button
                    v-if="canAttach"
                    icon="pi pi-paperclip"
                    text
                    rounded
                    size="small"
                    class="composer-attach-btn"
                    aria-label="Attach media"
                    v-tooltip.top="'Attach images, video, or files'"
                    :disabled="attachments.length >= maxFiles || uploading"
                    @click="pickFiles"
                />
                <span class="toolbar-divider" />
                <Button
                    icon="pi pi-bold"
                    text
                    rounded
                    size="small"
                    :severity="editor?.isActive('bold') ? 'secondary' : undefined"
                    aria-label="Bold"
                    v-tooltip.top="'Bold — ⌘B'"
                    @click="run('toggleBold')"
                />
                <Button
                    icon="pi pi-italic"
                    text
                    rounded
                    size="small"
                    :severity="editor?.isActive('italic') ? 'secondary' : undefined"
                    aria-label="Italic"
                    v-tooltip.top="'Italic — ⌘I'"
                    @click="run('toggleItalic')"
                />
                <Button
                    icon="pi pi-underline"
                    text
                    rounded
                    size="small"
                    :severity="editor?.isActive('underline') ? 'secondary' : undefined"
                    aria-label="Underline"
                    @click="run('toggleUnderline')"
                />
                <Button
                    icon="pi pi-stop"
                    text
                    rounded
                    size="small"
                    :severity="editor?.isActive('strike') ? 'secondary' : undefined"
                    aria-label="Strikethrough"
                    @click="run('toggleStrike')"
                />
                <span class="toolbar-divider" />
                <Button
                    icon="pi pi-list"
                    text
                    rounded
                    size="small"
                    :severity="editor?.isActive('bulletList') ? 'secondary' : undefined"
                    aria-label="Bullet list"
                    @click="run('toggleBulletList')"
                />
                <Button
                    icon="pi pi-sort-numeric-down"
                    text
                    rounded
                    size="small"
                    :severity="editor?.isActive('orderedList') ? 'secondary' : undefined"
                    aria-label="Numbered list"
                    @click="run('toggleOrderedList')"
                />
                <Button
                    icon="pi pi-comment"
                    text
                    rounded
                    size="small"
                    :severity="editor?.isActive('blockquote') ? 'secondary' : undefined"
                    aria-label="Quote"
                    @click="run('toggleBlockquote')"
                />
                <Button
                    icon="pi pi-code"
                    text
                    rounded
                    size="small"
                    :severity="editor?.isActive('code') || editor?.isActive('codeBlock') ? 'secondary' : undefined"
                    aria-label="Code"
                    @click="run('toggleCode')"
                />
                <Button icon="pi pi-link" text rounded size="small" aria-label="Link" @click="toggleLink" />
                <span class="composer-hint">{{ canAttach ? 'Drop files here · Shift + Enter for a new line' : 'Shift + Enter for a new line' }}</span>
            </div>

            <EditorContent :editor="editor" class="composer-input" />
            <div v-if="dragOver" class="composer-drop-hint">
                <i class="pi pi-cloud-upload" />
                <span>Drop files to attach</span>
            </div>
        </div>

        <div class="composer-send">
            <span class="composer-count" :class="{ limit: charCount > 7500 }">{{ charCount }}/8000</span>
            <Button
                :label="submitLabel"
                icon="pi pi-arrow-up"
                :disabled="!canSend"
                :loading="uploading"
                rounded
                class="send-button"
                @click="submit"
            />
        </div>
    </div>
</template>
