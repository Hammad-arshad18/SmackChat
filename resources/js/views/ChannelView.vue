<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import {
    ai,
    aiReadyBadge,
    clearCachedThreadSummary,
    initAiCapabilities,
    isDownloadRequired,
    prepareModel,
    readCachedThreadSummary,
    summarizeMessagesLocally,
    threadSummaryFingerprint,
    writeCachedThreadSummary,
} from '@/ai';
import api, { errorText } from '@/api';
import { plainText, revealMessage, sealDocument, searchMessages } from '@/e2e';
import Composer from '@/components/Composer.vue';
import EmojiPicker from '@/components/EmojiPicker.vue';
import MessageAttachments from '@/components/MessageAttachments.vue';
import MessageBody from '@/components/MessageBody.vue';
import { isEmojiOnlyText } from '@/lib/emojis';
import { reportViewing } from '@/push';
import { publishTyping, realtimeConnected, watchTyping, whenRealtime } from '@/realtime';
import { useAuthStore } from '@/stores/auth';
import { useOfficeStore } from '@/stores/office';
import { formatClock, formatDay, sameDay, timeAgo } from '@/time';

const props = defineProps({ channel: { type: String, required: true } });
const route = useRoute();
const router = useRouter();
const office = useOfficeStore();
const auth = useAuthStore();
const toast = useToast();
const confirm = useConfirm();

const messages = ref([]);
const replies = ref([]);
const thread = ref(null);
const hasMore = ref(false);
const loading = ref(true);
const loadingOlder = ref(false);
const scroller = ref(null);
const threadScroller = ref(null);
const composerRef = ref(null);
const threadComposerRef = ref(null);
const typing = ref('');
const editing = ref(null);
const detailsOpen = ref(false);
const searchOpen = ref(false);
const searchQuery = ref('');
const searchResults = ref([]);
const searchBusy = ref(false);
const focusedId = ref(null);
const atBottom = ref(true);
const savingChannel = ref(false);
const savingMembers = ref(false);
const memberIds = ref([]);
const reactionTarget = ref(null);
const reactionPop = ref(null);
const channelForm = reactive({ topic: '', description: '' });
const arriving = ref(new Set());
const emojis = ['👍', '❤️', '😄', '🎉', '✅', '👀', '🚀', '🔥'];
const channelSummary = ref('');
const channelSummaryOpen = ref(true);
const channelSummaryBusy = ref(false);
const threadSummary = ref('');
const threadSummaryOpen = ref(true);
const threadSummaryBusy = ref(false);
const threadSummaryHidden = ref(false);
const threadSummaryFromCache = ref(false);
const threadSummaryCachedAt = ref(null);
const downloadConfirm = ref(false);
const downloadBusy = ref(false);
const pendingDownloadAction = ref(null);
let poll;
let polling = true;
let stopTyping = () => {};
let typingTimer;
let searchTimer;
let searchToken = 0;
let focusTimer;
let channelSummaryRun = 0;
let threadSummaryRun = 0;
const arriveTimers = new Map();

const current = computed(() => office.channels.find((item) => item.slug === props.channel));
const isDm = computed(() => current.value?.type === 'dm');
const mentionPeople = computed(() => {
    if (!current.value || current.value.type === 'public') {
        return office.members;
    }

    const ids = new Set(current.value.member_ids || []);
    return office.members.filter((person) => ids.has(person.id));
});
const channelMembers = computed(() => {
    if (!current.value) return [];

    if (current.value.type === 'public') {
        return office.members;
    }

    const ids = new Set(memberIds.value);
    return office.members.filter((person) => ids.has(person.id));
});
const canManageMembers = computed(() => current.value?.type === 'private' || (current.value?.type === 'attendance' && office.manages));
const composerKey = computed(() => (editing.value ? `edit-${editing.value.id}` : `channel-${props.channel}`));
const composerPlaceholder = computed(() => `Message ${current.value?.label || ''}`);
const composerSubmit = computed(() => (editing.value ? 'Save changes' : 'Send'));
const channelHasSummaryMaterial = computed(() => messages.value.filter((item) => messagePlainForAi(item)).length >= 1);
const threadHasSummaryMaterial = computed(() => replies.value.filter((item) => messagePlainForAi(item)).length >= 1);
const threadAiMessages = computed(() => {
    if (!thread.value) {
        return [];
    }

    return [thread.value, ...replies.value]
        .filter((item) => messagePlainForAi(item))
        .map((item) => ({
            ...item,
            text: messagePlainForAi(item),
        }));
});
const threadFingerprint = computed(() => threadSummaryFingerprint(threadAiMessages.value));
const showThreadSummaryCard = computed(() => (
    !threadSummaryHidden.value && (threadSummaryBusy.value || !!threadSummary.value)
));
const showThreadSummarizeCta = computed(() => {
    if (threadSummaryBusy.value || showThreadSummaryCard.value) {
        return false;
    }

    if (threadSummaryHidden.value && threadSummary.value) {
        return true;
    }

    return replies.value.length >= 3;
});
const aiReady = computed(() => ai.engine !== 'unavailable');
const aiBadgeLabel = computed(() => aiReadyBadge());

function explainSummarizeUnavailable() {
    toast.add({
        severity: 'info',
        summary: 'On-device AI',
        detail: 'On-device summarization is not available in this browser. Nothing is sent to the server.',
        life: 4200,
    });
}

async function ensureSummaryReady(action) {
    try {
        await initAiCapabilities();
    } catch {
        explainSummarizeUnavailable();
        return false;
    }

    if (ai.engine === 'unavailable') {
        explainSummarizeUnavailable();
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

    pendingDownloadAction.value = action;
    downloadConfirm.value = true;
    return false;
}

async function confirmDownload() {
    if (downloadBusy.value) {
        return;
    }

    downloadBusy.value = true;

    try {
        await prepareModel();
        downloadConfirm.value = false;
        const action = pendingDownloadAction.value;
        pendingDownloadAction.value = null;

        if (ai.modelState === 'ready' && action) {
            await action();
        }
    } catch (error) {
        toast.add({
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
    pendingDownloadAction.value = null;
    downloadConfirm.value = false;
}

watch(current, (channel) => {
    channelForm.topic = channel?.topic || '';
    channelForm.description = channel?.description || '';
    memberIds.value = [...(channel?.member_ids || [])];
}, { immediate: true });

watch(() => props.channel, async () => {
    editing.value = null;
    detailsOpen.value = false;
    searchOpen.value = false;
    typing.value = '';
    dismissChannelSummary();
    resetThreadSummaryUi();
    await load();
});

watch(() => [route.query.m, route.query.reply, props.channel], async (value, previous) => {
    const channelChanged = !previous || value[2] !== previous[2];
    if (channelChanged || loading.value || !route.query.m || route.query.thread) {
        return;
    }

    await focusFromQuery();
});

watch(() => route.query.thread, async (id) => {
    if (loading.value) {
        return;
    }

    const next = queryToken(id);
    if ((thread.value?.ref || '') === next) {
        return;
    }

    if (!next) {
        thread.value = null;
        replies.value = [];
        return;
    }

    await restoreThread(next);
});

watch([() => current.value?.id, thread], () => {
    reportViewing(current.value?.id ?? null, thread.value?.id ?? null);
});

watch(searchQuery, (value) => {
    clearTimeout(searchTimer);
    const needle = value.trim();

    if (needle.length < 2) {
        searchToken += 1;
        searchResults.value = [];
        searchBusy.value = false;
        return;
    }

    searchBusy.value = true;
    const token = ++searchToken;
    searchTimer = setTimeout(async () => {
        try {
            const results = await searchMessages(office, needle, props.channel);
            if (token === searchToken) {
                searchResults.value = results;
            }
        } catch {
            if (token === searchToken) {
                searchResults.value = [];
            }
        } finally {
            if (token === searchToken) {
                searchBusy.value = false;
            }
        }
    }, 240);
});

function queryToken(value) {
    const token = Array.isArray(value) ? value[0] : value;

    return typeof token === 'string' && token.startsWith('v1.') ? token : '';
}

function endpoint(params = {}) {
    return api.get(`/workspaces/${office.slug}/channels/${props.channel}/messages`, { params });
}

async function load() {
    loading.value = true;
    const threadToken = queryToken(route.query.thread);
    if ((route.query.thread && !threadToken) || (route.query.m && !queryToken(route.query.m)) || (route.query.reply && !queryToken(route.query.reply))) {
        const cleaned = { ...route.query };
        if (!threadToken) delete cleaned.thread;
        if (!queryToken(route.query.m)) delete cleaned.m;
        if (!queryToken(route.query.reply)) delete cleaned.reply;
        router.replace({ query: cleaned });
    }
    if ((thread.value?.ref || '') !== threadToken) {
        thread.value = null;
        replies.value = [];
    }
    const focusToken = queryToken(route.query.m) || threadToken;
    const params = focusToken ? { focus: focusToken } : {};
    const { data } = await endpoint(params);
    messages.value = await hydrate(data.messages);
    hasMore.value = data.has_more;
    loading.value = false;
    clearUnread();

    if (threadToken) {
        await restoreThread(threadToken);
    } else if (queryToken(route.query.m)) {
        await focusFromQuery();
    } else {
        await stick();
    }

    setupTyping();
}

function syncThreadQuery(ref, history = 'replace') {
    const next = { ...route.query };
    delete next.m;
    delete next.reply;
    if (ref) {
        next.thread = ref;
    } else {
        delete next.thread;
    }

    const unchanged = String(route.query.thread || '') === String(next.thread || '')
        && !route.query.m
        && !route.query.reply;
    if (unchanged) {
        return;
    }

    const location = { query: next };
    if (history === 'push') {
        router.push(location);
    } else {
        router.replace(location);
    }
}

async function restoreThread(token) {
    let parent = messages.value.find((message) => message.ref === token && !message.parent_id);

    if (!parent && token) {
        const { data } = await endpoint({ focus: token });
        messages.value = await hydrate(data.messages);
        hasMore.value = data.has_more;
        parent = messages.value.find((message) => message.ref === token && !message.parent_id);
    }

    if (!parent) {
        syncThreadQuery(null);
        await stick();
        return;
    }

    await openThread(parent, { focus: false, history: 'replace' });
}

function closeThread() {
    thread.value = null;
    replies.value = [];
    resetThreadSummaryUi();
    syncThreadQuery(null);
}

function messagePlainForAi(message) {
    if (!message || message.deleted) {
        return '';
    }

    if (message.text?.trim()) {
        return message.text.trim();
    }

    if (message.document) {
        return plainText(message.document).trim();
    }

    return '';
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function formatAiMarkdown(value) {
    const escaped = escapeHtml(value).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    const lines = escaped.split('\n');
    const html = [];
    let inList = false;

    for (const line of lines) {
        const bullet = line.match(/^\s*[-*•]\s+(.+)$/);
        if (bullet) {
            if (!inList) {
                html.push('<ul>');
                inList = true;
            }
            html.push(`<li>${bullet[1]}</li>`);
            continue;
        }

        if (inList) {
            html.push('</ul>');
            inList = false;
        }

        if (!line.trim()) {
            continue;
        }

        html.push(`<p>${line}</p>`);
    }

    if (inList) {
        html.push('</ul>');
    }

    return html.join('') || '<p></p>';
}

function dismissChannelSummary() {
    channelSummaryRun += 1;
    channelSummary.value = '';
    channelSummaryBusy.value = false;
    channelSummaryOpen.value = true;
}

function resetThreadSummaryUi() {
    threadSummaryRun += 1;
    threadSummary.value = '';
    threadSummaryBusy.value = false;
    threadSummaryOpen.value = true;
    threadSummaryHidden.value = false;
    threadSummaryFromCache.value = false;
    threadSummaryCachedAt.value = null;
}

function dismissThreadSummary() {
    threadSummaryRun += 1;
    threadSummaryBusy.value = false;
    threadSummaryHidden.value = true;
}

function applyCachedThreadSummary(entry) {
    if (!entry?.text) {
        return false;
    }

    threadSummary.value = entry.text;
    threadSummaryOpen.value = true;
    threadSummaryHidden.value = false;
    threadSummaryFromCache.value = true;
    threadSummaryCachedAt.value = entry.savedAt || null;
    threadSummaryBusy.value = false;

    return true;
}

function restoreThreadSummaryFromCache() {
    if (!thread.value?.ref || !office.slug) {
        return false;
    }

    const fingerprint = threadFingerprint.value;
    if (!fingerprint) {
        return false;
    }

    const cached = readCachedThreadSummary(office.slug, thread.value.ref);
    if (!cached || cached.fingerprint !== fingerprint) {
        if (cached && cached.fingerprint !== fingerprint) {
            clearCachedThreadSummary(office.slug, thread.value.ref);
        }

        if (threadSummary.value && threadSummaryFromCache.value) {
            threadSummary.value = '';
            threadSummaryFromCache.value = false;
            threadSummaryCachedAt.value = null;
        }

        return false;
    }

    return applyCachedThreadSummary(cached);
}

watch(threadFingerprint, (fingerprint, previous) => {
    if (!thread.value?.ref) {
        return;
    }

    if (!fingerprint) {
        resetThreadSummaryUi();
        return;
    }

    if (restoreThreadSummaryFromCache()) {
        return;
    }

    if (previous && fingerprint !== previous && threadSummary.value) {
        // New or edited reply — drop the stale summary so we don't show outdated TL;DR
        threadSummary.value = '';
        threadSummaryFromCache.value = false;
        threadSummaryCachedAt.value = null;
        threadSummaryHidden.value = false;
        clearCachedThreadSummary(office.slug, thread.value.ref);
    }
});

async function summarizeChannel() {
    if (channelSummaryBusy.value) {
        return;
    }

    if (!channelHasSummaryMaterial.value) {
        toast.add({
            severity: 'info',
            summary: 'Summarize',
            detail: 'Load a few messages first, then try again.',
            life: 2800,
        });
        return;
    }

    if (!(await ensureSummaryReady(() => summarizeChannel()))) {
        return;
    }

    const token = ++channelSummaryRun;
    channelSummaryBusy.value = true;
    channelSummaryOpen.value = true;
    channelSummary.value = '';

    try {
        const payload = messages.value
            .filter((item) => messagePlainForAi(item))
            .map((item) => ({
                ...item,
                text: messagePlainForAi(item),
            }));

        const result = await summarizeMessagesLocally(
            payload,
            { type: 'key-points', length: 'short' },
            {
                onChunk: (chunk) => {
                    if (token === channelSummaryRun) {
                        channelSummary.value = chunk;
                    }
                },
            },
        );

        if (token === channelSummaryRun) {
            channelSummary.value = result;
        }
    } catch (error) {
        if (token === channelSummaryRun) {
            channelSummary.value = '';
            toast.add({
                severity: 'warn',
                summary: 'On-device AI',
                detail: errorText(error, 'Could not summarize this conversation locally.'),
                life: 3500,
            });
        }
    } finally {
        if (token === channelSummaryRun) {
            channelSummaryBusy.value = false;
        }
    }
}

async function summarizeThread({ force = false } = {}) {
    if (threadSummaryBusy.value || !thread.value) {
        return;
    }

    const fingerprint = threadFingerprint.value;
    if (!force && restoreThreadSummaryFromCache()) {
        return;
    }

    if (!threadHasSummaryMaterial.value && !messagePlainForAi(thread.value)) {
        toast.add({
            severity: 'info',
            summary: 'Summarize thread',
            detail: 'This thread does not have enough readable text yet.',
            life: 2800,
        });
        return;
    }

    if (!(await ensureSummaryReady(() => summarizeThread({ force })))) {
        return;
    }

    const token = ++threadSummaryRun;
    threadSummaryBusy.value = true;
    threadSummaryOpen.value = true;
    threadSummaryHidden.value = false;
    threadSummaryFromCache.value = false;
    threadSummaryCachedAt.value = null;
    threadSummary.value = '';

    try {
        const result = await summarizeMessagesLocally(
            threadAiMessages.value,
            { type: 'key-points', length: 'short' },
            {
                onChunk: (chunk) => {
                    if (token === threadSummaryRun) {
                        threadSummary.value = chunk;
                    }
                },
            },
        );

        if (token !== threadSummaryRun) {
            return;
        }

        threadSummary.value = result;
        if (result && fingerprint === threadFingerprint.value) {
            writeCachedThreadSummary(office.slug, thread.value.ref, result, fingerprint);
            threadSummaryFromCache.value = false;
            threadSummaryCachedAt.value = Date.now();
        }
    } catch (error) {
        if (token === threadSummaryRun) {
            threadSummary.value = '';
            toast.add({
                severity: 'warn',
                summary: 'On-device AI',
                detail: errorText(error, 'Could not summarize this thread locally.'),
                life: 3500,
            });
        }
    } finally {
        if (token === threadSummaryRun) {
            threadSummaryBusy.value = false;
        }
    }
}

async function focusFromQuery() {
    const parentToken = queryToken(route.query.m);
    let parent = messages.value.find((message) => message.ref === parentToken);

    if (!parent && parentToken) {
        const { data } = await endpoint({ focus: parentToken });
        messages.value = await hydrate(data.messages);
        hasMore.value = data.has_more;
        parent = messages.value.find((message) => message.ref === parentToken);
    }

    if (!parent) {
        return false;
    }

    const replyToken = queryToken(route.query.reply);
    if (replyToken) {
        await openThread(parent, { history: 'replace' });
        const reply = replies.value.find((message) => message.ref === replyToken);

        return reply ? highlightMessage(reply.id) : false;
    }

    return highlightMessage(parent.id);
}

async function older() {
    const first = messages.value[0];
    if (!first) return;
    loadingOlder.value = true;
    const height = scroller.value?.scrollHeight || 0;
    const { data } = await endpoint({ before: first.id });
    messages.value = [...await hydrate(data.messages), ...messages.value];
    hasMore.value = data.has_more;
    loadingOlder.value = false;
    await nextTick();
    if (scroller.value) {
        scroller.value.scrollTop += scroller.value.scrollHeight - height;
    }
}

async function stick() {
    await nextTick();
    if (scroller.value) {
        scroller.value.scrollTop = scroller.value.scrollHeight;
        atBottom.value = true;
    }
}

function onScroll() {
    const element = scroller.value;
    if (!element) return;
    atBottom.value = element.scrollHeight - element.scrollTop - element.clientHeight < 140;
}

function clearUnread() {
    const channel = office.channels.find((item) => item.slug === props.channel);
    if (channel?.unread) {
        channel.unread = 0;
    }
}

function mergeLive(existing, incoming) {
    if (!existing) {
        return incoming;
    }

    if (incoming.deleted) {
        return { ...existing, ...incoming, document: null, text: '' };
    }

    return {
        ...existing,
        ...incoming,
        document: incoming.document || existing.document,
        text: incoming.text || existing.text,
        excerpt: incoming.excerpt || existing.excerpt,
        sealed: incoming.sealed || existing.sealed,
    };
}

function localizeReactions(message, actorId) {
    if (!actorId || actorId === auth.user?.id) {
        return message;
    }

    const previous = messages.value.find((item) => item.id === message.id)
        || replies.value.find((item) => item.id === message.id)
        || (thread.value?.id === message.id ? thread.value : null);
    const mine = new Map((previous?.reactions || []).map((reaction) => [reaction.emoji, reaction.mine]));

    return {
        ...message,
        reactions: (message.reactions || []).map((reaction) => ({
            ...reaction,
            mine: Boolean(mine.get(reaction.emoji)),
        })),
    };
}

function upsert(list, message, { animate = false } = {}) {
    const index = list.value.findIndex((item) => item.id === message.id);
    if (index === -1) {
        list.value.push(message);
        if (animate) {
            markArriving(message.id);
        }
    } else {
        list.value[index] = message;
    }
}

function markArriving(id) {
    const next = new Set(arriving.value);
    next.add(id);
    arriving.value = next;
    clearTimeout(arriveTimers.get(id));
    arriveTimers.set(id, setTimeout(() => {
        const cleaned = new Set(arriving.value);
        cleaned.delete(id);
        arriving.value = cleaned;
        arriveTimers.delete(id);
    }, 700));
}

function canDelete(message) {
    if (!message || message.deleted || message.system_type) {
        return false;
    }

    return message.user?.id === auth.user.id || office.manages;
}

async function hydrate(list) {
    const opened = [];

    for (const message of list || []) {
        opened.push(await revealMessage(office, message));
    }

    return opened;
}

async function absorb(payload) {
    if (payload?.message) {
        payload = { ...payload, message: await revealMessage(office, payload.message) };
    }

    let message = payload?.message;
    if (!message || !current.value || message.channel_id !== current.value.id) {
        return;
    }

    message = localizeReactions(message, payload.actor_id);
    if (thread.value?.id === message.id) {
        thread.value = mergeLive(thread.value, message);
    }

    const isFresh = payload?.event === 'message.created' || (
        !messages.value.some((item) => item.id === message.id)
        && !replies.value.some((item) => item.id === message.id)
    );

    if (message.parent_id) {
        const parent = messages.value.find((item) => item.id === message.parent_id);
        if (parent && payload.parent) {
            parent.reply_count = payload.parent.reply_count;
            parent.last_reply_at = payload.parent.last_reply_at;
        }
        if (thread.value?.id === message.parent_id) {
            upsert(replies, mergeLive(replies.value.find((item) => item.id === message.id), message), { animate: isFresh && message.user?.id !== auth.user.id });
            if (payload?.event !== 'message.reacted') {
                nextTick(() => {
                    if (threadScroller.value) threadScroller.value.scrollTop = threadScroller.value.scrollHeight;
                });
            }
        }
        return;
    }

    if (payload?.event === 'message.reacted') {
        upsert(messages, mergeLive(messages.value.find((item) => item.id === message.id), message));
        return;
    }

    const nearBottom = atBottom.value;
    upsert(messages, mergeLive(messages.value.find((item) => item.id === message.id), message), { animate: isFresh && message.user?.id !== auth.user.id });
    clearUnread();
    if (nearBottom) {
        api.post(`/workspaces/${office.slug}/channels/${props.channel}/read`).catch(() => {});
    }
    if (nearBottom || message.user?.id === auth.user.id) {
        stick();
    }
}

async function send(payload) {
    const document = payload?.document || payload;
    const attachmentIds = payload?.attachment_ids || [];

    try {
        if (editing.value) {
            const sealed = await sealDocument(office, current.value, document);
            const { data } = await api.patch(`/workspaces/${office.slug}/messages/${editing.value.id}`, { sealed });
            await absorb(data);
            editing.value = null;
            return;
        }

        const sealed = await sealDocument(office, current.value, document);
        const { data } = await api.post(`/workspaces/${office.slug}/channels/${props.channel}/messages`, {
            sealed,
            attachment_ids: attachmentIds,
        });
        await absorb(data);
        await stick();
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Message', detail: errorText(error), life: 3000 });
    }
}

async function sendReply(payload) {
    if (!thread.value) return;
    const document = payload?.document || payload;
    const attachmentIds = payload?.attachment_ids || [];

    try {
        const sealed = await sealDocument(office, current.value, document);
        const { data } = await api.post(`/workspaces/${office.slug}/channels/${props.channel}/messages`, {
            sealed,
            parent_id: thread.value.id,
            attachment_ids: attachmentIds,
        });
        await absorb(data);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Reply', detail: errorText(error), life: 3000 });
    }
}

async function openThread(message, { focus = true, history = 'push' } = {}) {
    if (!message?.id) {
        return;
    }

    editing.value = null;
    const already = thread.value?.id === message.id && replies.value.length;
    if (thread.value?.id !== message.id) {
        resetThreadSummaryUi();
    }
    thread.value = message;
    syncThreadQuery(message.ref, history);
    if (!already) {
        replies.value = [];
    }

    try {
        const { data } = await endpoint({ parent_id: message.id });
        replies.value = await hydrate(data.messages);
        await nextTick();
        restoreThreadSummaryFromCache();
        if (threadScroller.value) threadScroller.value.scrollTop = threadScroller.value.scrollHeight;
        document.getElementById(`msg-${message.id}`)?.scrollIntoView({ block: 'center' });
        if (focus) threadComposerRef.value?.focus();
        api.post(`/workspaces/${office.slug}/channels/${props.channel}/read`).catch(() => {});
        clearUnread();
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Thread', detail: errorText(error), life: 3000 });
    }
}

async function react(message, emoji) {
    try {
        const { data } = await api.post(`/workspaces/${office.slug}/messages/${message.id}/reactions`, { emoji });
        await absorb(data);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Reaction', detail: errorText(error), life: 3000 });
    }
}

function isEmojiOnly(message) {
    if (!message || message.deleted || message.attachments?.length) {
        return false;
    }
    const text = message.text || (message.document ? plainText(message.document) : '');
    return isEmojiOnlyText(text);
}

function openReactions(event, message) {
    reactionTarget.value = message;
    reactionPop.value.toggle(event);
}

function pickReaction(emoji) {
    if (!reactionTarget.value) return;
    reactionPop.value?.hide?.();
    react(reactionTarget.value, emoji);
}

function remove(message) {
    confirm.require({
        message: 'This removes the message for everyone in the conversation.',
        header: 'Delete message',
        icon: 'pi pi-trash',
        rejectProps: { label: 'Cancel', severity: 'secondary', text: true },
        acceptProps: { label: 'Delete', severity: 'danger' },
        accept: async () => {
            try {
                const { data } = await api.delete(`/workspaces/${office.slug}/messages/${message.id}`);
                await absorb(data);
            } catch (error) {
                toast.add({ severity: 'error', summary: 'Delete', detail: errorText(error), life: 3000 });
            }
        },
    });
}

async function copyMessage(message) {
    try {
        await navigator.clipboard.writeText(message.text || '');
        toast.add({ severity: 'success', summary: 'Copied to clipboard', life: 1800 });
    } catch {
        toast.add({ severity: 'warn', summary: 'Could not copy', life: 2200 });
    }
}

function startEdit(message) {
    closeThread();
    editing.value = message;
    nextTick(() => composerRef.value?.focus());
}

function stacked(message, index, list) {
    const previous = list[index - 1];
    if (!previous || message.system_type || previous.system_type || previous.deleted) {
        return false;
    }

    const gap = new Date(message.created_at) - new Date(previous.created_at);

    return previous.user?.id === message.user?.id
        && sameDay(previous.created_at, message.created_at)
        && gap < 5 * 60 * 1000;
}

function showDay(message, index, list) {
    if (index === 0) return true;

    return !sameDay(list[index - 1].created_at, message.created_at);
}

function channelIcon(type) {
    if (type === 'dm') return 'pi pi-user';
    if (type === 'attendance') return 'pi pi-clipboard';
    if (type === 'private') return 'pi pi-lock';

    return 'pi pi-hashtag';
}

function subtitle() {
    if (isDm.value) return 'Only the two of you can read this conversation';
    if (current.value?.topic) return current.value.topic;
    if (current.value?.description) return current.value.description;

    return 'Start the conversation';
}

async function saveChannel() {
    savingChannel.value = true;
    try {
        await api.patch(`/workspaces/${office.slug}/channels/${props.channel}`, {
            topic: channelForm.topic,
            description: channelForm.description,
        });
        await office.refreshChannels();
        toast.add({ severity: 'success', summary: 'Conversation updated', life: 2200 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Conversation', detail: errorText(error), life: 3000 });
    } finally {
        savingChannel.value = false;
    }
}

async function saveMembers() {
    savingMembers.value = true;
    try {
        const { data } = await api.put(`/workspaces/${office.slug}/channels/${props.channel}/members`, {
            member_ids: memberIds.value,
        });
        memberIds.value = data.member_ids;
        await Promise.all([office.refreshChannels(), office.refreshMembers()]);
        toast.add({ severity: 'success', summary: 'Members updated', life: 2200 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Members', detail: errorText(error), life: 3000 });
    } finally {
        savingMembers.value = false;
    }
}

function openSearch() {
    searchOpen.value = true;
    searchQuery.value = '';
    searchResults.value = [];
}

async function highlightMessage(id) {
    await nextTick();
    const element = document.getElementById(`msg-${id}`);

    if (!element) {
        return false;
    }

    element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    focusedId.value = id;
    clearTimeout(focusTimer);
    focusTimer = setTimeout(() => { focusedId.value = null; }, 2600);

    return true;
}

async function goToResult(result) {
    searchOpen.value = false;
    const query = result.message.parent_ref
        ? { m: result.message.parent_ref, reply: result.message.ref }
        : { m: result.message.ref };
    const path = `/w/${office.slug}/c/${result.channel?.slug || props.channel}`;
    const dest = `${path}?${new URLSearchParams(query).toString()}`;

    if (route.fullPath === dest) {
        await focusFromQuery();
        return;
    }

    await (result.channel?.slug && result.channel.slug !== props.channel
        ? router.push({ path, query })
        : router.replace({ path, query }));
}

function prompt(text) {
    composerRef.value?.insertText(text);
}

function onLive(event) {
    absorb(event.detail);
}

function announceIncoming(message) {
    if (!message || message.user?.id === auth.user.id) {
        return;
    }

    window.dispatchEvent(new CustomEvent('smack-message', {
        detail: { event: 'message.created', message },
    }));
}

function setupTyping() {
    stopTyping();
    const channel = current.value;
    if (!channel) return;

    whenRealtime().then((connected) => {
        if (!connected || current.value?.id !== channel.id) return;
        stopTyping = watchTyping(office.workspace.id, channel.id, (payload) => {
            if (Number(payload.user_id) === auth.user.id) return;
            typing.value = payload.name;
            clearTimeout(typingTimer);
            typingTimer = setTimeout(() => { typing.value = ''; }, 2600);
        });
    });
}

async function pullNew() {
    const last = messages.value.at(-1);
    let changed = false;

    if (last) {
        const { data } = await endpoint({ after: last.id });
        for (const message of await hydrate(data.messages)) {
            upsert(messages, message, { animate: message.user?.id !== auth.user.id });
            announceIncoming(message);
            changed = true;
        }
        if (data.messages.length && atBottom.value) {
            stick();
            clearUnread();
            api.post(`/workspaces/${office.slug}/channels/${props.channel}/read`).catch(() => {});
        }
    }

    if (thread.value) {
        const params = { parent_id: thread.value.id };
        const lastReply = replies.value.at(-1);
        if (lastReply) params.after = lastReply.id;
        const { data } = await endpoint(params);
        for (const message of await hydrate(data.messages)) {
            upsert(replies, message, { animate: message.user?.id !== auth.user.id });
            announceIncoming(message);
        }
    }

    if (changed) {
        office.scheduleRefresh();
    }
}

function schedulePoll() {
    if (!polling) {
        return;
    }

    const wait = realtimeConnected() ? 20000 : 8000;
    poll = setTimeout(async () => {
        if (!polling) {
            return;
        }
        try {
            await pullNew();
        } catch {
            /* the next pass retries */
        }
        schedulePoll();
    }, wait);
}

onMounted(async () => {
    try {
        await initAiCapabilities();
    } catch {
        /* on-device AI stays hidden */
    }

    await load();
    window.addEventListener('smack-message', onLive);
    whenRealtime().finally(schedulePoll);
});

onBeforeUnmount(() => {
    reportViewing(null, null);
    polling = false;
    clearTimeout(poll);
    clearTimeout(typingTimer);
    clearTimeout(searchTimer);
    clearTimeout(focusTimer);
    arriveTimers.forEach((timer) => clearTimeout(timer));
    arriveTimers.clear();
    stopTyping();
    window.removeEventListener('smack-message', onLive);
});
</script>

<template>
    <div class="chat" :class="{ 'with-thread': thread }">
        <header class="chat-header">
            <div class="chat-identity">
                <PersonAvatar
                    v-if="isDm"
                    :person="current?.people?.[0]"
                    :name="current?.label"
                    size="large"
                />
                <span v-else class="chat-icon" :class="`type-${current?.type}`">
                    <i :class="channelIcon(current?.type)" />
                </span>
                <div class="chat-copy">
                    <div class="chat-title-line">
                        <h1>{{ current?.label || props.channel }}</h1>
                        <Tag v-if="current?.type === 'private'" value="Private" severity="secondary" />
                        <Tag v-if="current?.type === 'attendance'" value="Private ledger" severity="warn" />
                    </div>
                    <p>{{ subtitle() }}</p>
                </div>
            </div>

            <div class="chat-tools">
                <span v-if="aiBadgeLabel" class="ai-ready-badge chat-ai-badge">{{ aiBadgeLabel }}</span>
                <Button
                    v-if="aiReady"
                    icon="pi pi-sparkles"
                    text
                    rounded
                    :loading="channelSummaryBusy"
                    aria-label="Summarize recent"
                    v-tooltip.bottom="aiBadgeLabel || 'Summarize Recent · on-device'"
                    @click="summarizeChannel"
                />
                <Button icon="pi pi-search" text rounded aria-label="Search conversation" v-tooltip.bottom="'Search this conversation'" @click="openSearch" />
                <Button icon="pi pi-info-circle" text rounded aria-label="Conversation details" v-tooltip.bottom="'Details'" @click="detailsOpen = true" />
                <Tag
                    v-if="office.workspace.plan === 'standard'"
                    severity="secondary"
                    :value="`${office.workspace.retention_days}-day history`"
                    class="history-tag"
                />
                <Tag v-else severity="success" value="Forever archive" class="history-tag" />
            </div>
        </header>

        <div class="chat-body">
            <section ref="scroller" class="transcript" @scroll="onScroll">
                <div class="transcript-inner">
                    <Button
                        v-if="hasMore"
                        label="Load earlier messages"
                        icon="pi pi-arrow-up"
                        size="small"
                        severity="secondary"
                        outlined
                        :loading="loadingOlder"
                        class="older-button"
                        @click="older"
                    />

                    <section
                        v-if="channelSummaryBusy || channelSummary"
                        class="ai-summary-card channel-ai-summary"
                        :class="{ collapsed: !channelSummaryOpen && channelSummary }"
                    >
                        <header class="ai-summary-head">
                            <div>
                                <p class="card-kicker">On-device AI · Private</p>
                                <strong>Recent catch-up</strong>
                            </div>
                            <div class="ai-summary-actions">
                                <Button
                                    v-if="channelSummary"
                                    :icon="channelSummaryOpen ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
                                    text
                                    rounded
                                    size="small"
                                    aria-label="Toggle summary"
                                    @click="channelSummaryOpen = !channelSummaryOpen"
                                />
                                <Button
                                    icon="pi pi-times"
                                    text
                                    rounded
                                    size="small"
                                    aria-label="Dismiss summary"
                                    @click="dismissChannelSummary"
                                />
                            </div>
                        </header>
                        <p v-if="ai.modelState === 'downloading'" class="ai-summary-progress">
                            {{ ai.downloadLabel }}
                        </p>
                        <p v-else-if="channelSummaryBusy && !channelSummary" class="ai-summary-progress">
                            Summarizing on this device…
                        </p>
                        <div
                            v-if="channelSummary && channelSummaryOpen"
                            class="ai-summary-body"
                            v-html="formatAiMarkdown(channelSummary)"
                        />
                    </section>

                    <div v-if="loading" class="message-skeletons">
                        <Skeleton v-for="index in 5" :key="index" :height="index === 1 ? '4.2rem' : '3.2rem'" border-radius="16px" />
                    </div>

                    <div v-else-if="!messages.length" class="channel-empty">
                        <span class="empty-mark" :class="`type-${current?.type}`"><i :class="channelIcon(current?.type)" /></span>
                        <h2>{{ isDm ? `Say hi to ${current?.label}` : `Welcome to ${current?.label}` }}</h2>
                        <p>{{ current?.description || 'This is the very beginning. Make it useful.' }}</p>
                        <div class="prompt-chips">
                            <button type="button" @click="prompt('Hey team, here is what I am working on today: ')">Share an update</button>
                            <button type="button" @click="prompt('Quick question: ')">Ask a question</button>
                            <button type="button" @click="prompt('👋 Hello!')">Say hello</button>
                        </div>
                    </div>

                    <template v-for="(message, index) in messages" :key="message.id">
                        <div v-if="showDay(message, index, messages)" class="day-divider">
                            <span>{{ formatDay(message.created_at) }}</span>
                        </div>

                        <div
                            v-if="message.system_type === 'attendance'"
                            class="system-row"
                            :id="`msg-${message.id}`"
                        >
                            <Message severity="secondary" :closable="false" icon="pi pi-stopwatch">
                                {{ message.text }}
                            </Message>
                        </div>
                        <article
                            v-else
                            :id="`msg-${message.id}`"
                            class="msg-row"
                            :class="{
                                mine: message.user?.id === auth.user.id,
                                stacked: stacked(message, index, messages),
                                focused: focusedId === message.id,
                                deleted: message.deleted,
                                arriving: arriving.has(message.id),
                            }"
                        >
                            <div class="msg-avatar">
                                <PersonAvatar
                                    v-if="!stacked(message, index, messages) && message.user?.id !== auth.user.id"
                                    :person="message.user"
                                    color="#928875"
                                />
                            </div>
                            <div class="msg-main">
                                <div v-if="!stacked(message, index, messages)" class="msg-meta">
                                    <strong>{{ message.user?.id === auth.user.id ? 'You' : message.user?.name || 'Someone' }}</strong>
                                    <time>{{ formatClock(message.created_at) }}</time>
                                    <span v-if="message.edited_at" class="edited">edited</span>
                                </div>

                                <div class="msg-bubble" :class="{ 'is-emoji-only': isEmojiOnly(message) }">
                                    <p v-if="message.deleted" class="deleted-copy">This message was deleted.</p>
                                    <template v-else>
                                        <MessageBody v-if="message.document" :node="message.document" />
                                        <p v-else-if="message.cipher === 'e2e'" class="locked-copy">Waiting for the conversation key on this device.</p>
                                        <MessageAttachments
                                            v-if="message.attachments?.length"
                                            :items="message.attachments"
                                            :mine="message.user?.id === auth.user.id"
                                        />
                                    </template>

                                    <div v-if="!message.deleted" class="msg-tools">
                                        <Button
                                            icon="pi pi-face-smile"
                                            text
                                            rounded
                                            size="small"
                                            severity="secondary"
                                            aria-label="React"
                                            @click="openReactions($event, message)"
                                        />
                                        <Button
                                            v-if="!message.system_type"
                                            icon="pi pi-comments"
                                            text
                                            rounded
                                            size="small"
                                            severity="secondary"
                                            :aria-label="message.reply_count ? `${message.reply_count} replies` : 'Reply in thread'"
                                            @click="openThread(message)"
                                        />
                                        <Button
                                            icon="pi pi-copy"
                                            text
                                            rounded
                                            size="small"
                                            severity="secondary"
                                            aria-label="Copy message"
                                            @click="copyMessage(message)"
                                        />
                                        <Button
                                            v-if="message.user?.id === auth.user.id && !message.system_type"
                                            icon="pi pi-pencil"
                                            text
                                            rounded
                                            size="small"
                                            severity="secondary"
                                            aria-label="Edit message"
                                            @click="startEdit(message)"
                                        />
                                        <Button
                                            v-if="canDelete(message)"
                                            icon="pi pi-trash"
                                            text
                                            rounded
                                            size="small"
                                            severity="danger"
                                            aria-label="Delete message"
                                            @click="remove(message)"
                                        />
                                    </div>
                                </div>

                                <div v-if="message.reactions?.length || message.reply_count" class="msg-foot">
                                    <button
                                        v-for="reaction in message.reactions"
                                        :key="reaction.emoji"
                                        type="button"
                                        class="reaction"
                                        :class="{ mine: reaction.mine }"
                                        @click="react(message, reaction.emoji)"
                                    >
                                        {{ reaction.emoji }} <span>{{ reaction.count }}</span>
                                    </button>
                                    <button
                                        v-if="message.reply_count"
                                        type="button"
                                        class="thread-link"
                                        @click="openThread(message)"
                                    >
                                        <i class="pi pi-comments" />
                                        {{ message.reply_count }} {{ message.reply_count === 1 ? 'reply' : 'replies' }}
                                        <span v-if="message.last_reply_at">· {{ timeAgo(message.last_reply_at) }}</span>
                                    </button>
                                </div>
                            </div>
                        </article>
                    </template>
                </div>

                <Transition name="jump">
                    <Button
                        v-if="!atBottom && messages.length"
                        icon="pi pi-arrow-down"
                        rounded
                        class="jump-latest"
                        aria-label="Jump to latest"
                        v-tooltip.left="'Jump to latest'"
                        @click="stick"
                    />
                </Transition>
            </section>

            <footer class="composer-dock">
                <div v-if="typing && !thread" class="typing-line">
                    <span class="typing-dots"><i /><i /><i /></span>
                    <strong>{{ typing }}</strong> is typing…
                </div>
                <div v-if="editing" class="editing-line">
                    <i class="pi pi-pencil" />
                    <span>Editing a message</span>
                    <Button label="Cancel" link size="small" @click="editing = null" />
                </div>
                <Composer
                    ref="composerRef"
                    :key="composerKey"
                    :placeholder="composerPlaceholder"
                    :members="mentionPeople"
                    :channel="props.channel"
                    :initial="editing?.document || null"
                    :allow-attachments="!editing"
                    :submit-label="composerSubmit"
                    :draft-key="editing ? '' : `channel-${office.slug}-${props.channel}`"
                    @send="send"
                    @typing="current && publishTyping(office.workspace.id, current.id, { user_id: auth.user.id, name: auth.user.name })"
                />
            </footer>

            <aside v-if="thread" class="thread-panel">
                <header class="thread-head">
                    <div>
                        <p class="card-kicker">Thread</p>
                        <h2>{{ current?.label || 'Conversation' }}</h2>
                    </div>
                    <div class="thread-head-actions">
                        <Button
                            v-if="replies.length >= 3"
                            icon="pi pi-sparkles"
                            text
                            rounded
                            size="small"
                            :loading="threadSummaryBusy"
                            :severity="showThreadSummaryCard ? 'secondary' : undefined"
                            aria-label="Summarize thread"
                            v-tooltip.bottom="aiBadgeLabel || (showThreadSummaryCard ? 'Refresh thread summary · on-device' : 'Summarize thread · on-device')"
                            @click="summarizeThread({ force: !!threadSummary })"
                        />
                        <Button icon="pi pi-times" text rounded aria-label="Close thread" @click="closeThread" />
                    </div>
                </header>

                <div ref="threadScroller" class="thread-scroll">
                    <section
                        v-if="showThreadSummaryCard"
                        class="ai-summary-card thread-ai-summary"
                        :class="{ collapsed: !threadSummaryOpen && threadSummary }"
                    >
                        <header class="ai-summary-head">
                            <div>
                                <p class="card-kicker">On-device AI · Private</p>
                                <strong>Thread TL;DR</strong>
                                <small v-if="threadSummary && !threadSummaryBusy" class="ai-summary-meta">
                                    {{ threadSummaryFromCache ? 'Saved on this device' : 'Just generated' }}
                                    · stays until a new reply
                                </small>
                            </div>
                            <div class="ai-summary-actions">
                                <Button
                                    v-if="threadSummary && !threadSummaryBusy"
                                    icon="pi pi-refresh"
                                    text
                                    rounded
                                    size="small"
                                    aria-label="Refresh thread summary"
                                    v-tooltip.top="'Regenerate'"
                                    @click="summarizeThread({ force: true })"
                                />
                                <Button
                                    v-if="threadSummary"
                                    :icon="threadSummaryOpen ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
                                    text
                                    rounded
                                    size="small"
                                    aria-label="Toggle thread summary"
                                    @click="threadSummaryOpen = !threadSummaryOpen"
                                />
                                <Button
                                    icon="pi pi-times"
                                    text
                                    rounded
                                    size="small"
                                    aria-label="Hide thread summary"
                                    @click="dismissThreadSummary"
                                />
                            </div>
                        </header>
                        <p v-if="ai.modelState === 'downloading'" class="ai-summary-progress">
                            {{ ai.downloadLabel }}
                        </p>
                        <p v-else-if="threadSummaryBusy && !threadSummary" class="ai-summary-progress">
                            Summarizing on this device…
                        </p>
                        <div
                            v-if="threadSummary && threadSummaryOpen"
                            class="ai-summary-body"
                            v-html="formatAiMarkdown(threadSummary)"
                        />
                    </section>

                    <article class="thread-parent">
                        <div class="thread-parent-head">
                            <PersonAvatar :person="thread.user" color="#928875" />
                            <div><strong>{{ thread.user?.name }}</strong><time>{{ formatClock(thread.created_at) }}</time></div>
                        </div>
                        <div class="thread-parent-body" :class="{ 'is-emoji-only': isEmojiOnly(thread) }">
                            <MessageBody v-if="thread.document" :node="thread.document" />
                            <p v-else-if="thread.cipher === 'e2e'" class="locked-copy">Waiting for the conversation key on this device.</p>
                            <MessageAttachments
                                v-if="thread.attachments?.length"
                                :items="thread.attachments"
                                :mine="thread.user?.id === auth.user.id"
                            />
                        </div>
                        <div v-if="thread.reactions?.length" class="msg-foot">
                            <button
                                v-for="reaction in thread.reactions"
                                :key="reaction.emoji"
                                type="button"
                                class="reaction"
                                :class="{ mine: reaction.mine }"
                                @click="react(thread, reaction.emoji)"
                            >
                                {{ reaction.emoji }} <span>{{ reaction.count }}</span>
                            </button>
                        </div>
                    </article>

                    <p v-if="!replies.length" class="thread-empty">No replies yet.</p>
                    <div v-else class="thread-replies-row">
                        <div class="thread-replies-label">
                            <span>{{ replies.length }} {{ replies.length === 1 ? 'reply' : 'replies' }}</span>
                        </div>
                        <button
                            v-if="showThreadSummarizeCta"
                            type="button"
                            class="thread-summarize-cta"
                            @click="summarizeThread()"
                        >
                            <i :class="threadSummaryHidden && threadSummary ? 'pi pi-eye' : 'pi pi-sparkles'" />
                            {{ threadSummaryHidden && threadSummary ? 'Show summary' : 'Summarize thread' }}
                        </button>
                    </div>

                    <article
                        v-for="reply in replies"
                        :id="`msg-${reply.id}`"
                        :key="reply.id"
                        class="thread-reply"
                        :class="{
                            mine: reply.user?.id === auth.user.id,
                            deleted: reply.deleted,
                            focused: focusedId === reply.id,
                            arriving: arriving.has(reply.id),
                        }"
                    >
                        <PersonAvatar :person="reply.user" color="#928875" />
                        <div>
                            <div class="thread-reply-head">
                                <strong>{{ reply.user?.id === auth.user.id ? 'You' : reply.user?.name }}</strong>
                                <time>{{ formatClock(reply.created_at) }}</time>
                            </div>
                            <div class="thread-reply-body" :class="{ 'is-emoji-only': isEmojiOnly(reply) }">
                                <p v-if="reply.deleted" class="deleted-copy">This reply was deleted.</p>
                                <template v-else>
                                    <MessageBody v-if="reply.document" :node="reply.document" />
                                    <p v-else-if="reply.cipher === 'e2e'" class="locked-copy">Waiting for the conversation key on this device.</p>
                                    <MessageAttachments
                                        v-if="reply.attachments?.length"
                                        :items="reply.attachments"
                                        :mine="reply.user?.id === auth.user.id"
                                    />
                                </template>
                                <div v-if="!reply.deleted" class="msg-tools thread-tools">
                                    <Button 
                                        icon="pi pi-face-smile"
                                        text
                                        rounded
                                        size="small"
                                        severity="secondary"
                                        aria-label="React"
                                        @click="openReactions($event, reply)"
                                    />
                                    <Button
                                        v-if="canDelete(reply)"
                                        icon="pi pi-trash"
                                        text
                                        rounded
                                        size="small"
                                        severity="danger"
                                        aria-label="Delete reply"
                                        @click="remove(reply)"
                                    />
                                </div>
                            </div>
                            <div v-if="reply.reactions?.length" class="msg-foot">
                                <button
                                    v-for="reaction in reply.reactions"
                                    :key="reaction.emoji"
                                    type="button"
                                    class="reaction"
                                    :class="{ mine: reaction.mine }"
                                    @click="react(reply, reaction.emoji)"
                                >
                                    {{ reaction.emoji }} <span>{{ reaction.count }}</span>
                                </button>
                            </div>
                        </div>
                    </article>
                </div>

                <footer class="thread-composer">
                    <div v-if="typing" class="typing-line">
                        <span class="typing-dots"><i /><i /><i /></span>
                        <strong>{{ typing }}</strong> is typing…
                    </div>
                    <Composer
                        ref="threadComposerRef"
                        :key="`reply-${thread.id}`"
                        placeholder="Reply…"
                        submit-label="Reply"
                        :members="mentionPeople"
                        :channel="props.channel"
                        :draft-key="`reply-${office.slug}-${thread.id}`"
                        @send="sendReply"
                        @typing="current && publishTyping(office.workspace.id, current.id, { user_id: auth.user.id, name: auth.user.name })"
                    />
                </footer>
            </aside>
        </div>

        <Popover ref="reactionPop">
            <EmojiPicker for-reactions @select="pickReaction" />
        </Popover>

        <Drawer v-model:visible="detailsOpen" position="right" class="details-drawer">
            <template #header>
                <div class="dialog-title">
                    <span class="dialog-icon"><i class="pi pi-info-circle" /></span>
                    <div><strong>Conversation details</strong><small>Context, people, and history.</small></div>
                </div>
            </template>

            <div v-if="current" class="details-body">
                <section class="details-hero">
                    <span class="empty-mark small" :class="`type-${current.type}`"><i :class="channelIcon(current.type)" /></span>
                    <div>
                        <h2>{{ current.label }}</h2>
                        <p>{{ current.type === 'attendance' ? 'Private attendance ledger' : current.type === 'dm' ? 'Direct message' : `${current.type} channel` }}</p>
                    </div>
                </section>

                <section class="detail-section">
                    <header><span>About</span></header>
                    <FloatLabel variant="on">
                        <InputText id="detail-topic" v-model="channelForm.topic" fluid />
                        <label for="detail-topic">Topic</label>
                    </FloatLabel>
                    <FloatLabel variant="on">
                        <Textarea id="detail-description" v-model="channelForm.description" rows="3" auto-resize fluid />
                        <label for="detail-description">Description</label>
                    </FloatLabel>
                    <Button label="Save details" icon="pi pi-check" size="small" :loading="savingChannel" @click="saveChannel" />
                </section>

                <section class="detail-section">
                    <header>
                        <span>Members · {{ channelMembers.length }}</span>
                        <Tag v-if="current.type === 'public'" value="Everyone" severity="secondary" />
                    </header>
                    <ul class="member-list">
                        <li v-for="person in channelMembers" :key="person.id">
                            <span class="presence-avatar" :class="{ online: person.online }">
                                <PersonAvatar :person="person" />
                            </span>
                            <span><strong>{{ person.name }}</strong><small>{{ person.title || person.email }}</small></span>
                            <Tag :value="person.role" severity="secondary" />
                        </li>
                    </ul>

                    <template v-if="canManageMembers">
                        <p class="fine">Add or remove the people who can read this conversation.</p>
                        <MultiSelect
                            v-model="memberIds"
                            :options="office.members"
                            option-label="name"
                            option-value="id"
                            display="chip"
                            filter
                            placeholder="Choose members"
                            fluid
                        >
                            <template #option="slotProps">
                                <div class="select-person">
                                    <PersonAvatar :person="slotProps.option" />
                                    <span>{{ slotProps.option.name }}</span>
                                </div>
                            </template>
                        </MultiSelect>
                        <Button label="Update members" icon="pi pi-users" size="small" :loading="savingMembers" @click="saveMembers" />
                    </template>
                </section>

                <section class="detail-section">
                    <header><span>History</span></header>
                    <div class="history-note">
                        <i :class="office.workspace.plan === 'archive' ? 'pi pi-verified' : 'pi pi-history'" />
                        <div>
                            <strong>{{ office.workspace.plan === 'archive' ? 'Kept forever' : `${office.workspace.retention_days}-day history` }}</strong>
                            <p>{{ office.workspace.plan === 'archive' ? 'Every message stays in this workspace.' : 'Older messages are pruned automatically. Upgrade to keep everything.' }}</p>
                        </div>
                    </div>
                    <Button
                        v-if="office.isOwner && office.workspace.plan !== 'archive'"
                        label="Keep history forever"
                        icon="pi pi-sparkles"
                        size="small"
                        severity="secondary"
                        outlined
                        @click="router.push(`/w/${office.slug}/billing`)"
                    />
                </section>
            </div>
        </Drawer>

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
                    rewrite, draft, and summarize E2E messages privately offline. If the full model cannot
                    run here, a smaller one is used automatically.
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

        <Dialog v-model:visible="searchOpen" modal :style="{ width: '36rem' }" class="smack-dialog search-dialog">
            <template #header>
                <div class="dialog-title">
                    <span class="dialog-icon"><i class="pi pi-search" /></span>
                    <div><strong>Search this conversation</strong><small>Find a decision, a link, or a detail.</small></div>
                </div>
            </template>
            <div class="conversation-search">
                <IconField>
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="searchQuery" placeholder="Search messages in this conversation…" fluid autofocus />
                </IconField>
                <span v-if="searchBusy" class="search-status"><i class="pi pi-spin pi-spinner" /> Searching…</span>
                <div v-if="searchResults.length" class="search-results">
                    <button v-for="result in searchResults" :key="result.message.id" type="button" class="search-result" @click="goToResult(result)">
                        <span class="search-result-head">
                            <strong>{{ result.message.user?.name }}</strong>
                            <time>{{ formatDay(result.message.created_at) }} · {{ formatClock(result.message.created_at) }}</time>
                        </span>
                        <span class="search-result-body">{{ result.snippet }}</span>
                    </button>
                </div>
                <div v-else-if="searchQuery.length >= 2 && !searchBusy" class="search-empty">
                    <i class="pi pi-search" />
                    <p>No messages matched “{{ searchQuery }}”.</p>
                </div>
                <div v-else-if="searchQuery.length < 2" class="search-empty">
                    <i class="pi pi-comments" />
                    <p>Type at least two characters to search this conversation.</p>
                </div>
            </div>
        </Dialog>
    </div>
</template>
