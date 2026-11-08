<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import api, { errorText } from '@/api';
import Composer from '@/components/Composer.vue';
import MessageAttachments from '@/components/MessageAttachments.vue';
import MessageBody from '@/components/MessageBody.vue';
import { reportViewing } from '@/push';
import { publishTyping, watchTyping, whenRealtime } from '@/realtime';
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
let poll;
let stopTyping = () => {};
let typingTimer;
let searchTimer;
let searchToken = 0;
let focusTimer;
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
const composerKey = computed(() => {
    if (editing.value) return `edit-${editing.value.id}`;
    if (thread.value) return `reply-${thread.value.id}`;
    return `channel-${props.channel}`;
});
const composerPlaceholder = computed(() => (
    thread.value && !editing.value ? 'Reply in thread…' : `Message ${current.value?.label || ''}`
));
const composerSubmit = computed(() => {
    if (editing.value) return 'Save changes';
    if (thread.value) return 'Reply';
    return 'Send';
});

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
    await load();
});

watch(() => [route.query.m, route.query.reply], () => {
    if (route.query.m) {
        focusFromQuery();
    }
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
            const { data } = await api.get(`/workspaces/${office.slug}/search`, {
                params: { q: needle, channel: props.channel },
            });
            if (token === searchToken) {
                searchResults.value = data.results;
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

function endpoint(params = {}) {
    return api.get(`/workspaces/${office.slug}/channels/${props.channel}/messages`, { params });
}

async function load() {
    loading.value = true;
    thread.value = null;
    const { data } = await endpoint();
    messages.value = data.messages;
    hasMore.value = data.has_more;
    loading.value = false;
    clearUnread();
    await stick();
    setupTyping();

    if (route.query.m) {
        await focusFromQuery();
    }
}

async function focusFromQuery() {
    const parentId = Number(route.query.m);
    const parent = messages.value.find((message) => message.id === parentId);

    if (!parent) {
        return false;
    }

    if (route.query.reply) {
        await openThread(parent);
        return highlightMessage(Number(route.query.reply));
    }

    return highlightMessage(parentId);
}

async function older() {
    const first = messages.value[0];
    if (!first) return;
    loadingOlder.value = true;
    const height = scroller.value?.scrollHeight || 0;
    const { data } = await endpoint({ before: first.id });
    messages.value = [...data.messages, ...messages.value];
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

function absorb(payload) {
    const message = payload?.message;
    if (!message || !current.value || message.channel_id !== current.value.id) {
        return;
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
            upsert(replies, message, { animate: isFresh && message.user?.id !== auth.user.id });
            nextTick(() => {
                if (threadScroller.value) threadScroller.value.scrollTop = threadScroller.value.scrollHeight;
            });
        }
        return;
    }

    const nearBottom = atBottom.value;
    upsert(messages, message, { animate: isFresh && message.user?.id !== auth.user.id });
    clearUnread();
    if (nearBottom) {
        api.post(`/workspaces/${office.slug}/channels/${props.channel}/read`).catch(() => {});
    }
    if (nearBottom || message.user?.id === auth.user.id) {
        stick();
    }
}

async function send(payload) {
    if (thread.value && !editing.value) {
        await sendReply(payload);
        return;
    }

    const document = payload?.document || payload;
    const attachmentIds = payload?.attachment_ids || [];

    try {
        if (editing.value) {
            const { data } = await api.patch(`/workspaces/${office.slug}/messages/${editing.value.id}`, { document });
            absorb(data);
            editing.value = null;
            return;
        }

        const { data } = await api.post(`/workspaces/${office.slug}/channels/${props.channel}/messages`, {
            document,
            attachment_ids: attachmentIds,
        });
        absorb(data);
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
        const { data } = await api.post(`/workspaces/${office.slug}/channels/${props.channel}/messages`, {
            document,
            parent_id: thread.value.id,
            attachment_ids: attachmentIds,
        });
        absorb(data);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Reply', detail: errorText(error), life: 3000 });
    }
}

async function openThread(message) {
    editing.value = null;
    thread.value = message;
    replies.value = [];
    try {
        const { data } = await endpoint({ parent_id: message.id });
        replies.value = data.messages;
        await nextTick();
        if (threadScroller.value) threadScroller.value.scrollTop = threadScroller.value.scrollHeight;
        composerRef.value?.focus();
        api.post(`/workspaces/${office.slug}/channels/${props.channel}/read`).catch(() => {});
        clearUnread();
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Thread', detail: errorText(error), life: 3000 });
    }
}

async function react(message, emoji) {
    try {
        const { data } = await api.post(`/workspaces/${office.slug}/messages/${message.id}/reactions`, { emoji });
        absorb({ message: data.message });
        const reply = replies.value.find((item) => item.id === data.message.id);
        if (reply) {
            upsert(replies, data.message);
        }
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Reaction', detail: errorText(error), life: 3000 });
    }
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
                absorb(data);
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
    thread.value = null;
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

    if (result.message.parent_id) {
        const parent = messages.value.find((message) => message.id === result.message.parent_id);
        if (parent) {
            await openThread(parent);
            await highlightMessage(result.message.id);
            return;
        }
    }

    const found = await highlightMessage(result.message.id);

    if (!found) {
        toast.add({
            severity: 'info',
            summary: 'Older message',
            detail: 'Load earlier messages to jump to that result in the transcript.',
            life: 3200,
        });
    }
}

function prompt(text) {
    composerRef.value?.insertText(text);
}

function onLive(event) {
    absorb(event.detail);
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

onMounted(async () => {
    await load();
    window.addEventListener('smack-message', onLive);
    poll = setInterval(async () => {
        const last = messages.value.at(-1);
        let changed = false;

        if (last) {
            const { data } = await endpoint({ after: last.id });
            data.messages.forEach((message) => {
                upsert(messages, message, { animate: message.user?.id !== auth.user.id });
                changed = true;
            });
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
            data.messages.forEach((message) => upsert(replies, message, { animate: message.user?.id !== auth.user.id }));
        }

        if (changed) {
            office.refreshChannels();
        }
    }, 8000);
});

onBeforeUnmount(() => {
    reportViewing(null, null);
    clearInterval(poll);
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
                <Avatar
                    v-if="isDm"
                    :label="(current?.label || '?').slice(0, 1)"
                    shape="circle"
                    size="large"
                    :style="{ background: current?.people?.[0]?.avatar_color || '#0e7c66', color: '#fff' }"
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
                                <Avatar
                                    v-if="!stacked(message, index, messages) && message.user?.id !== auth.user.id"
                                    :label="(message.user?.name || '?').slice(0, 1)"
                                    shape="circle"
                                    :style="{ background: message.user?.avatar_color || '#928875', color: '#fff' }"
                                />
                            </div>
                            <div class="msg-main">
                                <div v-if="!stacked(message, index, messages)" class="msg-meta">
                                    <strong>{{ message.user?.id === auth.user.id ? 'You' : message.user?.name || 'Someone' }}</strong>
                                    <time>{{ formatClock(message.created_at) }}</time>
                                    <span v-if="message.edited_at" class="edited">edited</span>
                                </div>

                                <div class="msg-bubble">
                                    <p v-if="message.deleted" class="deleted-copy">This message was deleted.</p>
                                    <template v-else>
                                        <MessageBody v-if="message.document" :node="message.document" />
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

            <aside v-if="thread" class="thread-panel">
                <header class="thread-head">
                    <div>
                        <p class="card-kicker">Thread</p>
                        <h2>{{ thread.reply_count + 1 }} message{{ thread.reply_count === 0 ? '' : 's' }}</h2>
                    </div>
                    <Button icon="pi pi-times" text rounded aria-label="Close thread" @click="thread = null" />
                </header>

                <div ref="threadScroller" class="thread-scroll">
                    <article class="thread-parent">
                        <div class="thread-parent-head">
                            <Avatar :label="(thread.user?.name || '?').slice(0, 1)" shape="circle" :style="{ background: thread.user?.avatar_color || '#928875', color: '#fff' }" />
                            <div><strong>{{ thread.user?.name }}</strong><time>{{ formatClock(thread.created_at) }}</time></div>
                        </div>
                        <div class="thread-parent-body">
                            <MessageBody v-if="thread.document" :node="thread.document" />
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

                    <p v-if="!replies.length" class="thread-empty">No replies yet. Start the thread below.</p>

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
                        <Avatar
                            :label="(reply.user?.name || '?').slice(0, 1)"
                            shape="circle"
                            :style="{ background: reply.user?.avatar_color || '#928875', color: '#fff' }"
                        />
                        <div>
                            <div class="thread-reply-head">
                                <strong>{{ reply.user?.id === auth.user.id ? 'You' : reply.user?.name }}</strong>
                                <time>{{ formatClock(reply.created_at) }}</time>
                            </div>
                            <div class="thread-reply-body">
                                <p v-if="reply.deleted" class="deleted-copy">This reply was deleted.</p>
                                <template v-else>
                                    <MessageBody v-if="reply.document" :node="reply.document" />
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
            </aside>
        </div>

        <footer class="composer-dock">
            <div v-if="typing" class="typing-line">
                <span class="typing-dots"><i /><i /><i /></span>
                <strong>{{ typing }}</strong> is typing…
            </div>
            <div v-if="editing" class="editing-line">
                <i class="pi pi-pencil" />
                <span>Editing a message</span>
                <Button label="Cancel" link size="small" @click="editing = null" />
            </div>
            <div v-else-if="thread" class="editing-line">
                <i class="pi pi-comments" />
                <span>Replying in thread</span>
                <Button label="Cancel" link size="small" @click="thread = null" />
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
                :draft-key="editing ? '' : thread ? `reply-${office.slug}-${thread.id}` : `channel-${office.slug}-${props.channel}`"
                @send="send"
                @typing="current && publishTyping(office.workspace.id, current.id, { user_id: auth.user.id, name: auth.user.name })"
            />
        </footer>

        <Popover ref="reactionPop">
            <div class="emoji-grid">
                <button v-for="emoji in emojis" :key="emoji" type="button" @click="pickReaction(emoji)">{{ emoji }}</button>
            </div>
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
                                <Avatar :label="person.name.slice(0, 1)" shape="circle" :style="{ background: person.avatar_color, color: '#fff' }" />
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
                                    <Avatar :label="slotProps.option.name.slice(0, 1)" shape="circle" :style="{ background: slotProps.option.avatar_color, color: '#fff' }" />
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
