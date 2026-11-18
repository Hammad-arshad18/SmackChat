<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import CallVideo from '@/components/CallVideo.vue';
import { unlockAudio } from '@/lib/ringtone';
import { useAuthStore } from '@/stores/auth';
import { useCallStore } from '@/stores/calls';
import { useOfficeStore } from '@/stores/office';

const calls = useCallStore();
const office = useOfficeStore();
const auth = useAuthStore();
const toast = useToast();

const dock = ref(null);
const expanded = ref(false);
let baseTitle = '';
let titleTimer = null;
let titleFlip = false;
let lastTitle = '';
let notification = null;

const timer = computed(() => {
    const total = calls.elapsed;
    const minutes = String(Math.floor(total / 60)).padStart(2, '0');
    const seconds = String(total % 60).padStart(2, '0');

    return `${minutes}:${seconds}`;
});

const activeCall = computed(() => calls.call || calls.incoming);
const isDm = computed(() => activeCall.value?.channel_type === 'dm');
const noun = computed(() => (isDm.value ? 'call' : 'huddle'));
const kindLabel = computed(() => (activeCall.value?.kind === 'video' ? 'video' : 'voice'));
const caller = computed(() => {
    const target = calls.incoming;

    if (!target) {
        return null;
    }

    return target.participants?.find((person) => person.user_id === target.started_by)
        || target.participants?.[0]
        || null;
});
const remoteList = computed(() => calls.others);
const localVideo = computed(() => Boolean(calls.localStream?.getVideoTracks?.().length));
const hasVideo = computed(() => localVideo.value || remoteList.value.some((person) => remoteVideo(person.user_id)));
const waitingName = computed(() => {
    if (isDm.value) {
        return calls.call?.channel_label || remoteList.value[0]?.name || 'them';
    }

    return calls.call?.channel_label || 'the channel';
});
const outgoingLabel = computed(() => (isDm.value ? `Calling ${waitingName.value}…` : `Ringing ${waitingName.value}…`));

function remoteVideo(userId) {
    return Boolean(calls.remoteStreams[userId]?.getVideoTracks?.().length);
}

function personFor(userId) {
    return calls.participants.find((person) => person.user_id === userId) || { user_id: userId, name: 'Teammate' };
}

function onNotice(event) {
    toast.add({
        severity: 'secondary',
        summary: 'Calls',
        detail: event.detail?.message || '',
        life: 4000,
    });
}

function notifyIncoming(payload) {
    closeNotification();

    if (!payload || typeof Notification === 'undefined' || Notification.permission !== 'granted') {
        return;
    }

    if (!document.hidden) {
        return;
    }

    try {
        notification = new Notification(`Incoming ${kindLabel.value} ${noun.value}`, {
            body: `${caller.value?.name || 'A teammate'} is ${noun.value === 'call' ? 'calling' : 'starting a huddle'}`,
            tag: 'smack-call',
            requireInteraction: true,
        });
        notification.onclick = () => {
            window.focus();
            notification?.close();
            notification = null;
        };
    } catch {
        /* notifications are a bonus, the in-app ring is the source of truth */
    }
}

function closeNotification() {
    try {
        notification?.close();
    } catch {
        /* already gone */
    }

    notification = null;
}

function restoreTitle() {
    clearInterval(titleTimer);
    titleTimer = null;

    // Only undo a title we wrote, never a title the router set meanwhile.
    if (lastTitle && document.title === lastTitle) {
        document.title = baseTitle || 'SmackChat';
    }

    lastTitle = '';
}

function ringTitle(payload) {
    if (!payload) {
        restoreTitle();

        return;
    }

    baseTitle = document.title;
    titleFlip = false;
    clearInterval(titleTimer);
    lastTitle = `📞 Incoming ${kindLabel.value} ${noun.value}`;
    document.title = lastTitle;
    titleTimer = setInterval(() => {
        titleFlip = !titleFlip;
        lastTitle = titleFlip ? `📞 ${caller.value?.name || 'Incoming call'}` : `Incoming ${noun.value} · SmackChat`;
        document.title = lastTitle;
    }, 1600);
}

watch(() => calls.incoming, (payload) => {
    ringTitle(payload);
    notifyIncoming(payload);
});

watch(() => calls.inCall, (active) => {
    if (!active) {
        collapse();
    }
});

function onFullscreenChange() {
    if (!document.fullscreenElement && expanded.value) {
        expanded.value = false;
    }
}

async function toggleExpanded() {
    if (expanded.value) {
        collapse();

        return;
    }

    expanded.value = true;

    try {
        if (document.fullscreenEnabled && dock.value && !document.fullscreenElement) {
            await dock.value.requestFullscreen({ navigationUI: 'hide' });
        }
    } catch {
        /* the in-app full screen still works */
    }
}

async function collapse() {
    expanded.value = false;

    if (document.fullscreenElement) {
        try {
            await document.exitFullscreen();
        } catch {
            /* already out */
        }
    }
}

onMounted(() => {
    window.addEventListener('smack-call-notice', onNotice);
    window.addEventListener('pointerdown', unlockAudio, { once: true });
    document.addEventListener('fullscreenchange', onFullscreenChange);
    calls.attach();
});

onBeforeUnmount(() => {
    window.removeEventListener('smack-call-notice', onNotice);
    window.removeEventListener('pointerdown', unlockAudio);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    restoreTitle();
    closeNotification();
    calls.detach();
});

watch(() => office.slug, async (slug) => {
    calls.detach();
    collapse();

    if (slug) {
        await nextTick();
        calls.attach();
    }
});
</script>

<template>
    <div
        v-if="calls.inCall"
        ref="dock"
        class="call-dock"
        :class="{ expanded }"
        role="dialog"
        :aria-label="`${kindLabel} ${noun}`"
    >
        <header class="call-dock-head">
            <div class="call-dock-title">
                <p class="card-kicker">{{ kindLabel }} {{ noun }}</p>
                <strong>{{ calls.call.channel_label || 'Call' }}</strong>
            </div>
            <div class="call-dock-meta">
                <span v-if="calls.outgoing" class="call-ringing">Ringing…</span>
                <span v-else class="call-timer">{{ timer }}</span>
                <Button
                    :icon="expanded ? 'pi pi-window-minimize' : 'pi pi-expand'"
                    text
                    rounded
                    size="small"
                    :aria-label="expanded ? 'Exit full screen' : 'Full screen'"
                    v-tooltip.bottom="expanded ? 'Exit full screen' : 'Full screen'"
                    @click="toggleExpanded"
                />
            </div>
        </header>

        <div class="call-stage" :class="{ audio: !hasVideo, outgoing: calls.outgoing }">
            <div v-if="calls.outgoing" class="call-outgoing">
                <span class="call-outgoing-pulse"><i class="pi pi-phone" /></span>
                <strong>{{ outgoingLabel }}</strong>
                <small>Waiting for an answer</small>
            </div>

            <template v-else>
                <div class="call-tile local">
                    <CallVideo v-if="localVideo" :stream="calls.localStream" muted mirror />
                    <div v-else class="call-audio-avatar">
                        <PersonAvatar :person="auth.user" :name="auth.user?.name" size="large" />
                    </div>
                    <span class="call-tile-name">
                        You
                        <i v-if="!calls.micOn" class="pi pi-microphone call-muted" />
                    </span>
                </div>

                <div v-for="person in remoteList" :key="person.user_id" class="call-tile">
                    <CallVideo v-if="remoteVideo(person.user_id)" :stream="calls.remoteStreams[person.user_id]" muted />
                    <div v-else class="call-audio-avatar">
                        <PersonAvatar :person="personFor(person.user_id)" :name="person.name" size="large" />
                    </div>
                    <span class="call-tile-name">{{ person.name }}</span>
                    <audio
                        v-if="calls.remoteStreams[person.user_id]"
                        :ref="(el) => el && (el.srcObject = calls.remoteStreams[person.user_id])"
                        autoplay
                    />
                </div>
            </template>

            <p v-if="calls.connection === 'connecting' && !calls.outgoing" class="call-connecting">Connecting…</p>
        </div>

        <footer class="call-controls">
            <Button
                icon="pi pi-microphone"
                rounded
                :severity="calls.micOn ? 'secondary' : 'danger'"
                :aria-label="calls.micOn ? 'Mute' : 'Unmute'"
                :class="{ 'call-off': !calls.micOn }"
                v-tooltip.top="calls.micOn ? 'Mute' : 'Unmute'"
                @click="calls.toggleMic()"
            />
            <Button
                icon="pi pi-video"
                rounded
                :severity="calls.cameraOn ? 'secondary' : 'danger'"
                :aria-label="calls.cameraOn ? 'Turn camera off' : 'Turn camera on'"
                :class="{ 'call-off': !calls.cameraOn }"
                v-tooltip.top="calls.cameraOn ? 'Camera off' : 'Camera on'"
                @click="calls.toggleCamera()"
            />
            <Button
                icon="pi pi-desktop"
                rounded
                :severity="calls.sharing ? 'success' : 'secondary'"
                :aria-label="calls.sharing ? 'Stop sharing' : 'Share screen'"
                v-tooltip.top="calls.sharing ? 'Stop sharing' : 'Share screen'"
                @click="calls.toggleShare()"
            />
            <Button
                :label="calls.outgoing ? 'Cancel' : 'Leave'"
                icon="pi pi-phone"
                severity="danger"
                rounded
                class="call-leave"
                @click="calls.leave()"
            />
        </footer>
        <span class="call-plan-note">
            {{ calls.outgoing ? 'Free peer-to-peer ' + noun : (office.workspace?.calls?.group_enabled ? 'Group ' + noun : 'One-to-one free ' + noun) }}
        </span>
    </div>

    <div
        v-if="calls.incoming && !calls.inCall"
        class="call-ring"
        role="dialog"
        aria-modal="true"
        :aria-label="`Incoming ${kindLabel} ${noun}`"
    >
        <div class="call-ring-stage">
            <p class="card-kicker">Incoming {{ kindLabel }} {{ noun }}</p>
            <div class="call-ring-avatar">
                <span class="call-ring-wave wave-one" />
                <span class="call-ring-wave wave-two" />
                <PersonAvatar :person="caller" :name="caller?.name || calls.incoming.channel_label" size="xlarge" />
            </div>
            <h2>{{ caller?.name || calls.incoming.channel_label || 'A teammate' }}</h2>
            <p class="call-ring-sub">
                <template v-if="isDm">Direct {{ kindLabel }} call</template>
                <template v-else>
                    {{ calls.incoming.channel_label }}
                    <span class="dot-sep">·</span>
                    Huddle
                </template>
            </p>
            <p class="call-ring-hint">Ringing on this device</p>

            <div class="call-ring-actions">
                <Button
                    label="Decline"
                    icon="pi pi-phone"
                    severity="danger"
                    rounded
                    class="call-ring-decline"
                    @click="calls.declineIncoming()"
                />
                <Button
                    label="Accept"
                    icon="pi pi-phone"
                    rounded
                    class="call-ring-accept"
                    @click="calls.acceptIncoming()"
                />
            </div>
        </div>
    </div>
</template>
