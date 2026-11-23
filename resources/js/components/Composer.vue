<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import Mention from '@tiptap/extension-mention';
import { useToast } from '@/toast';
import { takeAgentFollowup } from '@/agent';
import {
    ai,
    aiReadyBadge,
    initAiCapabilities,
    isDownloadRequired,
    prepareModel,
    rewriteDraftLocally,
} from '@/ai';
import AgentAsk from '@/components/AgentAsk.vue';
import api, { errorText } from '@/api';
import { warmAttachmentCache } from '@/lib/attachments';
import { useOfficeStore } from '@/stores/office';
import EmojiPicker from '@/components/EmojiPicker.vue';

const props = defineProps({
    placeholder: { type: String, default: 'Write a message' },
    members: { type: Array, default: () => [] },
    initial: { type: Object, default: null },
    submitLabel: { type: String, default: 'Send' },
    draftKey: { type: String, default: '' },
    compact: { type: Boolean, default: false },
    channel: { type: String, default: '' },
    allowAttachments: { type: Boolean, default: true },
    contextMessages: { type: Array, default: () => [] },
});

const emit = defineEmits(['send', 'typing']);

const office = useOfficeStore();
const toast = useToast();
// A phone or tablet with only an on-screen keyboard. A tablet with a
// trackpad or mouse keeps desktop behaviour (Enter sends).
const touchKeyboard = typeof window !== 'undefined'
    && window.matchMedia?.('(pointer: coarse)').matches
    && !window.matchMedia?.('(any-pointer: fine)').matches;
const emojis = ['👍', '❤️', '😄', '🎉', '✅', '👀', '🚀', '🔥', '✨', '🙏', '💡', '☕', '🎯', '🙌', '😅', '🫡', '🧠', '📌', '💬', '⚡'];
const emojiPop = ref(null);
const linkPop = ref(null);
const aiPop = ref(null);
const writePop = ref(null);
const writeBtn = ref(null);
const agentAsk = ref(null);
const agentPhase = ref('');
const followupSteps = ref([]);
const linkUrl = ref('');
const mentionOpen = ref(false);
const mentionItems = ref([]);
const mentionIndex = ref(0);
const charCount = ref(0);
const fileInput = ref(null);
const attachments = ref([]);
const uploading = ref(false);
const dragOver = ref(false);
const aiBusy = ref(false);
const aiMode = ref('');
const previousDraftBeforeAi = ref(null);
const downloadConfirm = ref(false);
const downloadBusy = ref(false);
const pendingAiAction = ref(null);
let mentionCommand = null;
let typingAt = 0;
let aiRun = 0;

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
        StarterKit.configure({ heading: false, link: false, underline: false }),
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

                // Phone keyboards have no Shift key. There, Enter is a new line
                // and the Send button sends.
                if (touchKeyboard) {
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

onMounted(async () => {
    charCount.value = editor.value?.getText().length || 0;
    if (editor.value?.isEmpty && props.initial) {
        charCount.value = 0;
    }

    try {
        await initAiCapabilities();
    } catch {
        /* the AI toolbar simply stays hidden */
    }

    const steps = takeAgentFollowup(props.channel);

    if (!steps.length) {
        return;
    }

    if (steps.some((step) => step.action === 'reply' || step.action === 'summarize')) {
        const start = Date.now();

        while (!props.contextMessages.length && Date.now() - start < 5000) {
            await new Promise((resolve) => setTimeout(resolve, 250));
        }
    }

    followupSteps.value = steps;
    await nextTick();
    const target = writeBtn.value?.$el || writeBtn.value;

    if (target) {
        writePop.value?.show?.({ currentTarget: target }, target);
    }
});

const canAttach = computed(() => props.allowAttachments && !!props.channel && !props.initial);
const isEmpty = computed(() => charCount.value === 0 && !attachments.value.length);
const canSend = computed(() => !isEmpty.value && !uploading.value && !aiBusy.value && charCount.value <= 8000);
const aiReady = computed(() => ai.engine !== 'unavailable');
const aiBadgeLabel = computed(() => aiReadyBadge());
const aiStatusLabel = computed(() => {
    if (ai.modelState === 'downloading') {
        return '';
    }

    if (!aiBusy.value) {
        return '';
    }

    if (agentPhase.value) {
        return agentPhase.value;
    }

    return 'Rewriting the draft';
});

const aiActions = computed(() => {
    const items = [];

    if (aiBadgeLabel.value) {
        items.push({ label: aiBadgeLabel.value, disabled: true, class: 'composer-ai-badge' });
        items.push({ separator: true });
    }

    items.push(
        {
            label: 'Make Professional',
            icon: 'pi pi-briefcase',
            command: () => runAiRewrite({ tone: 'more-formal', length: 'as-is', action: 'rewrite' }),
        },
        {
            label: 'Make Shorter',
            icon: 'pi pi-minus',
            command: () => runAiRewrite({ tone: 'as-is', length: 'shorter', action: 'rewrite' }),
        },
        {
            label: 'More Casual',
            icon: 'pi pi-sun',
            command: () => runAiRewrite({ tone: 'more-casual', length: 'as-is', action: 'rewrite' }),
        },
        {
            label: 'Fix Grammar',
            icon: 'pi pi-check-circle',
            command: () => runAiRewrite({ tone: 'as-is', length: 'as-is', action: 'fix-grammar' }),
        },
        { separator: true },
        {
            label: 'Ask AI…',
            icon: 'pi pi-sparkles',
            command: () => openWritePop(),
        },
    );

    return items;
});

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

function explainAiUnavailable(action = 'use on-device AI') {
    toast.add({
        severity: 'info',
        summary: 'On-device AI',
        detail: `This browser cannot ${action} on this device. Nothing is sent to the server.`,
        life: 4200,
    });
}

function toggleAi(event) {
    if (aiBusy.value) {
        return;
    }

    if (!aiReady.value) {
        explainAiUnavailable('polish drafts');
        return;
    }

    if (isEmpty.value) {
        toast.add({
            severity: 'info',
            summary: 'AI Polish',
            detail: 'Write a draft first, or use Ask AI to draft one from notes.',
            life: 3200,
        });
        return;
    }

    aiPop.value?.toggle(event);
}

async function openWritePop(event) {
    if (aiBusy.value) {
        return;
    }

    if (!aiReady.value) {
        explainAiUnavailable('write drafts');
        return;
    }

    aiPop.value?.hide?.();

    const target = writeBtn.value?.$el || writeBtn.value || event?.currentTarget;

    if (!target) {
        return;
    }

    if (event?.currentTarget) {
        writePop.value?.toggle(event);
    } else {
        writePop.value?.show({ currentTarget: target }, target);
    }

    await nextTick();
    agentAsk.value?.focus?.();
}

/**
 * Make sure the chosen engine has its model before an action runs.
 * Gemini Nano is prepared straight from the click; the Transformers.js model
 * needs an explicit one-time opt-in because it downloads hundreds of MB.
 */
async function ensureAiReady(action) {
    try {
        await initAiCapabilities();
    } catch {
        explainAiUnavailable('run on-device AI');
        return false;
    }

    if (ai.engine === 'unavailable') {
        explainAiUnavailable('run on-device AI');
        return false;
    }

    if (ai.modelState === 'ready') {
        return true;
    }

    if (ai.engine === 'gemini-nano') {
        try {
            await prepareModel();
            return ai.modelState === 'ready';
        } catch (error) {
            if (!isDownloadRequired(error)) {
                toast.add({
                    severity: 'warn',
                    summary: 'On-device AI',
                    detail: errorText(error, 'Could not set up on-device AI.'),
                    life: 3800,
                });
                return false;
            }
        }
    }

    pendingAiAction.value = action;
    downloadConfirm.value = true;
    return false;
}

async function confirmDownload() {
    if (downloadBusy.value) {
        return;
    }

    downloadBusy.value = true;
    const stop = toast.followPercent('ai-model', 'Downloading model', () => ai.downloadPercent);

    try {
        await prepareModel();
        stop();
        toast.finish('ai-model', {
            summary: 'Offline AI ready',
            detail: 'The model is cached on this device.',
            life: 2800,
        });
        downloadConfirm.value = false;
        const action = pendingAiAction.value;
        pendingAiAction.value = null;

        if (ai.modelState === 'ready' && action) {
            await action();
        }
    } catch (error) {
        stop();
        toast.finish('ai-model', {
            severity: 'warn',
            summary: 'Offline AI',
            detail: errorText(error, 'Could not start the offline AI model on this device.'),
            life: 4600,
        });
    } finally {
        downloadBusy.value = false;
    }
}

function cancelDownload() {
    pendingAiAction.value = null;
    downloadConfirm.value = false;
}

function inlineNodesFromLine(line) {
    const nodes = [];
    const people = [...props.members].sort((left, right) => right.name.length - left.name.length);
    let cursor = 0;
    let index = 0;

    while (index < line.length) {
        if (line[index] === '@') {
            const rest = line.slice(index + 1);
            const person = people.find((member) => {
                const name = member.name;
                if (!rest.toLowerCase().startsWith(name.toLowerCase())) {
                    return false;
                }

                const edge = rest[name.length];
                return edge == null || /[\s.,!?;:)\]}]/.test(edge);
            });

            if (person) {
                if (index > cursor) {
                    nodes.push({ type: 'text', text: line.slice(cursor, index) });
                }

                nodes.push({
                    type: 'mention',
                    attrs: { id: String(person.id), label: person.name },
                });

                index += 1 + person.name.length;
                cursor = index;
                continue;
            }
        }

        index += 1;
    }

    if (cursor < line.length) {
        nodes.push({ type: 'text', text: line.slice(cursor) });
    }

    return nodes.length ? nodes : undefined;
}

function documentFromPlainText(text) {
    const lines = String(text || '').replace(/\r\n/g, '\n').split('\n');
    const content = lines.map((line) => {
        const trimmed = line.trimEnd();
        if (!trimmed) {
            return { type: 'paragraph' };
        }

        return {
            type: 'paragraph',
            content: inlineNodesFromLine(trimmed),
        };
    });

    return {
        type: 'doc',
        content: content.length ? content : [{ type: 'paragraph' }],
    };
}

function applyAiText(text) {
    if (!editor.value) {
        return;
    }

    const document = documentFromPlainText(text);
    editor.value.commands.setContent(document, true);
    charCount.value = editor.value.getText().length;
    saveDraft(editor.value.getJSON());
}

function beginAiRun() {
    const snapshot = editor.value?.getJSON() || { type: 'doc', content: [{ type: 'paragraph' }] };
    previousDraftBeforeAi.value = JSON.stringify(snapshot);
    aiBusy.value = true;

    return ++aiRun;
}

function restorePreviousDraft() {
    if (!previousDraftBeforeAi.value || !editor.value) {
        return;
    }

    try {
        editor.value.commands.setContent(JSON.parse(previousDraftBeforeAi.value), true);
        charCount.value = editor.value.getText().length;
        saveDraft(editor.value.getJSON());
    } catch {
        /* keep streamed text if restore fails */
    }
}

async function runAiRewrite(options) {
    const current = editor.value?.getText() || '';
    if (!current.trim() || aiBusy.value) {
        return;
    }

    aiPop.value?.hide?.();
    if (!(await ensureAiReady(() => performRewrite(options)))) {
        return;
    }

    await performRewrite(options);
}

async function performRewrite(options) {
    const current = editor.value?.getText() || '';
    if (!current.trim() || aiBusy.value) {
        return;
    }

    aiMode.value = 'polish';
    const token = beginAiRun();
    toast.loading('ai-draft', 'Polishing', 'On this device');

    try {
        const result = await rewriteDraftLocally(current, options, {
            onChunk: (chunk) => {
                if (token !== aiRun) {
                    return;
                }

                applyAiText(chunk);
            },
        });

        if (token !== aiRun) {
            toast.dismiss('ai-draft');
            return;
        }

        applyAiText(result || current);
        toast.finish('ai-draft', { summary: 'Polished', life: 1600 });
    } catch (error) {
        if (token !== aiRun) {
            toast.dismiss('ai-draft');
            return;
        }

        restorePreviousDraft();
        previousDraftBeforeAi.value = null;
        toast.finish('ai-draft', {
            severity: 'warn',
            summary: 'On-device AI',
            detail: errorText(error, 'Could not polish this draft locally.'),
            life: 3500,
        });
    } finally {
        if (token === aiRun) {
            aiBusy.value = false;
            aiMode.value = '';
        }
    }
}

function composerDraft() {
    return editor.value?.getText() || '';
}

function onAgentDraft(text) {
    if (!previousDraftBeforeAi.value) {
        previousDraftBeforeAi.value = JSON.stringify(editor.value?.getJSON() || { type: 'doc', content: [{ type: 'paragraph' }] });
    }

    applyAiText(text);
}

function onAgentRevert() {
    restorePreviousDraft();
    previousDraftBeforeAi.value = null;
}

function onAgentClose() {
    writePop.value?.hide?.();
}

function onAgentDeliver() {
    aiBusy.value = false;
    agentPhase.value = '';
    submit();
}

function onAgentStarted() {
    followupSteps.value = [];
}

function undoAi() {
    if (!previousDraftBeforeAi.value || !editor.value) {
        return;
    }

    try {
        editor.value.commands.setContent(JSON.parse(previousDraftBeforeAi.value), true);
        charCount.value = editor.value.getText().length;
        saveDraft(editor.value.getJSON());
    } catch {
        /* ignore */
    }

    previousDraftBeforeAi.value = null;
}

function focus() {
    editor.value?.commands.focus();
}

function clear() {
    editor.value?.commands.clearContent(true);
    charCount.value = 0;
    previousDraftBeforeAi.value = null;
    aiBusy.value = false;
    aiMode.value = '';
    agentPhase.value = '';
    writePop.value?.hide?.();
    aiRun += 1;
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

        if (data.attachment?.id) {
            warmAttachmentCache(data.attachment.id, file);
        }

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
        toast.add({
            severity: 'error',
            summary: 'Upload failed',
            detail: errorText(error, 'Could not upload that file.'),
            life: 3500,
        });
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
                <PersonAvatar :person="item" />
                <span>{{ item.name }}</span>
                <small>{{ item.title || 'Teammate' }}</small>
            </button>
        </div>

        <Popover ref="emojiPop">
            <EmojiPicker @select="insertEmoji" />
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

        <Menu ref="aiPop" :model="aiActions" popup class="composer-ai-menu" />

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

        <div v-if="ai.modelState === 'downloading'" class="ai-download-row">
            <div class="ai-download-copy">
                <i class="pi pi-cloud-download" />
                <span>{{ ai.downloadLabel }}</span>
            </div>
            <ProgressBar :value="ai.downloadPercent" :show-value="false" class="ai-download-bar" />
        </div>

        <Popover ref="writePop" class="composer-write-pop" :pt="{ root: { class: 'composer-write-pop' } }">
            <AgentAsk
                ref="agentAsk"
                v-model:busy="aiBusy"
                v-model:phase="agentPhase"
                :messages="contextMessages"
                :channel-slug="channel"
                :get-draft="composerDraft"
                :set-draft="onAgentDraft"
                :initial-steps="followupSteps"
                @revert="onAgentRevert"
                @deliver="onAgentDeliver"
                @started="onAgentStarted"
                @close="onAgentClose"
            />
        </Popover>

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
                <span class="toolbar-divider" />
                <Button
                    v-if="aiReady"
                    ref="writeBtn"
                    icon="pi pi-pen-to-square"
                    text
                    rounded
                    size="small"
                    class="composer-ai-btn"
                    :severity="aiBusy && agentPhase ? 'secondary' : undefined"
                    :loading="aiBusy && !!agentPhase"
                    :disabled="aiBusy"
                    aria-label="Ask AI"
                    v-tooltip.top="aiBadgeLabel || 'Ask AI · on-device'"
                    @click="openWritePop"
                />
                <Button
                    v-if="aiReady"
                    icon="pi pi-sparkles"
                    text
                    rounded
                    size="small"
                    class="composer-ai-btn"
                    :severity="aiBusy && aiMode === 'polish' ? 'secondary' : undefined"
                    :loading="aiBusy && aiMode === 'polish'"
                    :disabled="aiBusy"
                    aria-label="AI rewrite"
                    v-tooltip.top="aiBadgeLabel || (isEmpty ? 'AI rewrite · write a draft first' : 'AI rewrite · on-device')"
                    @click="toggleAi"
                />
                <Button
                    v-if="previousDraftBeforeAi && !aiBusy"
                    label="Undo AI"
                    text
                    size="small"
                    class="composer-ai-undo"
                    aria-label="Undo AI"
                    @click="undoAi"
                />
                <span v-if="aiBadgeLabel" class="ai-ready-badge">{{ aiBadgeLabel }}</span>
                <span class="composer-hint">
                    <template v-if="aiStatusLabel">{{ aiStatusLabel }}</template>
                    <template v-else-if="touchKeyboard">Tap send when you're ready</template>
                    <template v-else>{{ canAttach ? 'Drop files here · Shift + Enter for a new line' : 'Shift + Enter for a new line' }}</template>
                </span>
            </div>

            <EditorContent :editor="editor" class="composer-input" :class="{ 'is-ai-streaming': aiBusy }" />
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

        <Dialog v-model:visible="downloadConfirm" modal :style="{ width: '28rem' }" class="smack-dialog">
            <template #header>
                <div class="dialog-title">
                    <span class="dialog-icon"><i class="pi pi-download" /></span>
                    <div><strong>Download Offline AI</strong><small>One-time setup · stays on this device</small></div>
                </div>
            </template>
            <div class="ai-download-dialog">
                <p>
                    Download Offline AI ({{ ai.modelSizeHint }})? Downloads once to this device so you can
                    rewrite, draft, summarize, and run agent actions on E2E messages privately offline.
                    If the full model cannot run here, a smaller one is used automatically.
                </p>
                <div v-if="ai.modelState === 'downloading'" class="ai-download-row">
                    <div class="ai-download-copy">
                        <i class="pi pi-cloud-download" />
                        <span>{{ ai.downloadLabel }}</span>
                    </div>
                    <ProgressBar :value="ai.downloadPercent" :show-value="false" class="ai-download-bar" />
                </div>
                <div class="link-actions">
                    <Button label="Not now" text severity="secondary" size="small" :disabled="downloadBusy" @click="cancelDownload" />
                    <Button
                        label="Download Once & Enable"
                        icon="pi pi-download"
                        size="small"
                        :loading="downloadBusy"
                        :disabled="ai.modelState === 'downloading'"
                        @click="confirmDownload"
                    />
                </div>
            </div>
        </Dialog>
    </div>
</template>
