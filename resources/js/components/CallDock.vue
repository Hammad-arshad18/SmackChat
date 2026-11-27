<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useToast } from '@/toast';
import CallAudio from '@/components/CallAudio.vue';
import CallVideo from '@/components/CallVideo.vue';
import { unlockAudio } from '@/lib/ringtone';
import { useAuthStore } from '@/stores/auth';
import { useCallStore } from '@/stores/calls';
import { useOfficeStore } from '@/stores/office';

const calls = useCallStore();
const office = useOfficeStore();
const route = useRoute();
const auth = useAuthStore();
const toast = useToast();

const dock = ref(null);
const expanded = ref(false);
// Folds the dock down to its title and buttons so the chat stays usable.
const minimized = ref(false);
const soundBlocked = ref(false);
const pinned = ref(null);
const manualPin = ref(false);
// Phones (and some desktop browsers) cannot share a screen at all.
const canShare = typeof navigator !== 'undefined' && Boolean(navigator.mediaDevices?.getDisplayMedia);
let dockObserver = null;
let baseTitle = '';
let titleTimer = null;
let titleFlip = false;
let lastTitle = '';
let notification = null;
let ringTag = '';

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
const ringName = computed(() => caller.value?.name || calls.incoming?.channel_label || 'A teammate');
const ringIcon = computed(() => (kindLabel.value === 'video' ? 'pi pi-video' : 'pi pi-phone'));
// The same words the call push uses, so the screen and the notification match.
const ringLine = computed(() => (isDm.value
    ? `Incoming ${kindLabel.value} call`
    : `Huddle in #${calls.incoming?.channel_label || 'channel'}`));
const remoteList = computed(() => calls.others);
const waiting = computed(() => calls.outgoing && remoteList.value.length === 0);
const localVideo = computed(() => Boolean(calls.localStream?.getVideoTracks?.().length));
const hasVideo = computed(() => localVideo.value || remoteList.value.some((person) => remoteVideo(person.user_id)));
const waitingName = computed(() => {
    if (isDm.value) {
        return calls.call?.channel_label || remoteList.value[0]?.name || 'them';
    }

    return calls.call?.channel_label || 'the channel';
});
const outgoingLabel = computed(() => (isDm.value ? `Calling ${waitingName.value}…` : `Ringing ${waitingName.value}…`));
const status = computed(() => {
    if (calls.outgoing) {
        return { text: 'Ringing…', timer: false };
    }

    if (calls.connection === 'failed') {
        return { text: 'Reconnecting…', timer: false };
    }

    if (calls.connection === 'connecting') {
        return { text: 'Connecting…', timer: false };
    }

    return { text: timer.value, timer: true };
});

const tiles = computed(() => [
    {
        id: 'me',
        name: 'You',
        local: true,
        video: localVideo.value,
        share: calls.sharing,
        stream: calls.localStream,
        person: auth.user,
    },
    ...remoteList.value.map((person) => ({
        id: person.user_id,
        name: person.name,
        local: false,
        video: remoteVideo(person.user_id),
        share: Boolean(calls.remoteSharing?.[person.user_id]),
        stream: calls.remoteStreams[person.user_id],
        person: personFor(person.user_id),
    })),
]);
const sharer = computed(() => tiles.value.find((tile) => tile.share) || null);
const stageMode = computed(() => expanded.value || hasVideo.value || Boolean(sharer.value));
const focusTile = computed(() => {
    if (manualPin.value) {
        return tiles.value.find((tile) => tile.id === pinned.value) || null;
    }

    if (sharer.value) {
        return sharer.value;
    }

    if (!stageMode.value) {
        return null;
    }

    return tiles.value.find((tile) => !tile.local && tile.video)
        || tiles.value.find((tile) => tile.video)
        || null;
});
const orderedTiles = computed(() => {
    const focus = focusTile.value;

    if (!focus) {
        return tiles.value;
    }

    return [focus, ...tiles.value.filter((tile) => tile.id !== focus.id)];
});
// Two people: the other person fills the stage and you sit in a corner.
const duo = computed(() => Boolean(focusTile.value) && tiles.value.length === 2);
const showPins = computed(() => tiles.value.length > 2);

function togglePin(id) {
    if (manualPin.value && pinned.value === id) {
        manualPin.value = false;
        pinned.value = null;

        return;
    }

    pinned.value = id;
    manualPin.value = true;
}

/**
 * Tapping a small tile brings that person to the front. With two people it
 * swaps who is large.
 */
function focusOn(tile) {
    if (focusTile.value && focusTile.value.id !== tile.id) {
        togglePin(tile.id);
    }
}

watch(sharer, (tile) => {
    // Open up for someone else's screen, not for your own: a full-screen
    // call would cover the very thing you are sharing.
    if (tile && !tile.local) {
        expanded.value = true;
        minimized.value = false;
    }
});

function remoteVideo(userId) {
    // Camera off: show the avatar, not the frozen last frame.
    if (calls.remoteCamera?.[userId] === false && !calls.remoteSharing?.[userId]) {
        return false;
    }

    const tracks = calls.remoteStreams[userId]?.getVideoTracks?.() || [];

    return tracks.some((track) => track.readyState === 'live' && track.enabled && !track.muted);
}

function personFor(userId) {
    return calls.participants.find((person) => person.user_id === userId) || { user_id: userId, name: 'Teammate' };
}

async function pumpAudio() {
    const media = [...document.querySelectorAll('.call-dock audio')]
        .filter((node) => node.srcObject?.getAudioTracks?.().length);

    if (!media.length) {
        soundBlocked.value = false;

        return;
    }

    const results = await Promise.allSettled(media.map((node) => node.play()));
    soundBlocked.value = results.some((result) => result.status === 'rejected'
        && result.reason?.name !== 'AbortError');
}

watch(() => calls.remoteAudio, () => {
    nextTick(pumpAudio);
    setTimeout(pumpAudio, 900);
});

function onNotice(event) {
    toast.add({
        severity: 'secondary',
        summary: 'Calls',
        detail: event.detail?.message || '',
        life: 4000,
    });
}

/**
 * Ring through the system when nobody is looking at the app: a tab in the
 * background, or a window behind another one. It uses the same tag and data
 * as the call push, so the two never stack and a tap is handled the same way.
 */
async function notifyIncoming(payload) {
    await closeNotification();

    if (!payload || typeof Notification === 'undefined' || Notification.permission !== 'granted') {
        return;
    }

    if (document.visibilityState === 'visible' && document.hasFocus()) {
        return;
    }

    // The caller is the headline, as on a phone.
    const title = ringName.value;
    const body = ringLine.value;
    const tag = `smack-call-${payload.id}`;
    const data = {
        url: `/w/${office.slug}/c/${payload.channel_slug}?call=${payload.id}`,
        call_id: payload.id,
        workspace: office.slug,
        kind: 'call',
    };
    ringTag = tag;

    try {
        const registration = await navigator.serviceWorker?.getRegistration('/');

        if (registration?.showNotification) {
            const open = await registration.getNotifications({ tag });

            // The push got here first, or the ring was answered meanwhile.
            if (open.length || calls.incoming?.id !== payload.id) {
                return;
            }

            const icon = `${window.location.origin}/icons/smack-192.png`;

            await registration.showNotification(title, {
                body,
                tag,
                data,
                icon,
                badge: icon,
                requireInteraction: true,
                renotify: true,
                vibrate: [600, 300, 600, 300, 600],
                actions: [
                    { action: 'answer', title: 'Answer' },
                    { action: 'decline', title: 'Decline' },
                ],
            }).catch(() => registration.showNotification(title, { body, tag, data }));

            return;
        }
    } catch {
        /* fall through to the page notification */
    }

    try {
        notification = new Notification(title, { body, tag, requireInteraction: true });
        notification.onclick = () => {
            window.focus();
            notification?.close();
            notification = null;
        };
    } catch {
        /* notifications are a bonus, the in-app ring is the source of truth */
    }
}

async function closeNotification() {
    try {
        notification?.close();
    } catch {
        /* already gone */
    }

    notification = null;

    const tag = ringTag;
    ringTag = '';

    if (!tag) {
        return;
    }

    try {
        const registration = await navigator.serviceWorker?.getRegistration('/');
        const open = await registration?.getNotifications({ tag }) || [];

        // Leave a "Missed call" that replaced the ring where it is.
        open.filter((item) => item.data?.kind === 'call').forEach((item) => item.close());
    } catch {
        /* nothing to close */
    }
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

/**
 * Publish the dock's height so the chat can keep its composer above a docked
 * call on a phone instead of hiding Send under it.
 */
function trackDockHeight(node) {
    dockObserver?.disconnect();
    dockObserver = null;
    const root = document.documentElement;

    if (!node || typeof ResizeObserver === 'undefined') {
        root.style.removeProperty('--call-dock-h');

        return;
    }

    dockObserver = new ResizeObserver(() => {
        root.style.setProperty('--call-dock-h', `${Math.ceil(node.getBoundingClientRect().height)}px`);
    });
    dockObserver.observe(node);
}

watch(dock, trackDockHeight);

watch(() => calls.incoming, (payload) => {
    ringTitle(payload);
    notifyIncoming(payload);
});

watch(() => calls.inCall, (active) => {
    minimized.value = false;
    soundBlocked.value = false;

    if (!active) {
        pinned.value = null;
        manualPin.value = false;
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
    minimized.value = false;

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
    trackDockHeight(null);
    calls.detach();
});

watch(() => route.query.call, (id) => {
    if (id) {
        calls.joinFromLink();
    }
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
        :class="{ expanded, stage: stageMode, minimized: minimized && !expanded, duo }"
        role="dialog"
        :aria-label="`${kindLabel} ${noun}`"
    >
        <header class="call-dock-head">
            <div class="call-dock-title">
                <p class="card-kicker">{{ kindLabel }} {{ noun }}</p>
                <strong>{{ calls.call.channel_label || 'Call' }}</strong>
            </div>
            <div class="call-dock-meta">
                <span class="call-status" :class="status.timer ? 'timer' : 'pulse'" aria-live="polite">{{ status.text }}</span>
                <Button
                    v-if="!expanded"
                    :icon="minimized ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
                    text
                    rounded
                    size="small"
                    :aria-label="minimized ? `Show the ${noun}` : `Minimize the ${noun}`"
                    v-tooltip.bottom="minimized ? 'Show' : 'Minimize'"
                    @click="minimized = !minimized"
                />
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

        <div class="call-stage" :class="{ outgoing: waiting, 'has-pin': Boolean(focusTile) && !waiting }" @pointerdown="pumpAudio">
            <div v-if="waiting" class="call-outgoing">
                <span class="call-outgoing-pulse"><i class="pi pi-phone" /></span>
                <strong>{{ outgoingLabel }}</strong>
                <small>Waiting for an answer</small>
            </div>

            <template v-else>
                <div
                    v-for="tile in orderedTiles"
                    :key="tile.id"
                    class="call-tile"
                    :class="{ local: tile.local, share: tile.share, pinned: focusTile?.id === tile.id, film: focusTile && focusTile.id !== tile.id }"
                    @click="focusOn(tile)"
                >
                    <!-- Picture only. Voices play from the sinks below. -->
                    <CallVideo
                        v-if="tile.stream && tile.video"
                        :stream="tile.stream"
                        muted
                        :mirror="tile.local && calls.cameraOn && !calls.sharing"
                        :fit="tile.share ? 'contain' : 'cover'"
                    />
                    <div v-else class="call-audio-avatar">
                        <PersonAvatar :person="tile.person" :name="tile.name" :size="focusTile?.id === tile.id ? 'xlarge' : 'large'" />
                    </div>
                    <button
                        v-if="showPins"
                        type="button"
                        class="call-pin"
                        :class="{ active: manualPin && pinned === tile.id }"
                        :aria-label="manualPin && pinned === tile.id ? `Unpin ${tile.name}` : `Pin ${tile.name}`"
                        @click.stop="togglePin(tile.id)"
                    >
                        <i class="pi pi-thumbtack" />
                    </button>
                    <span class="call-tile-name">
                        {{ tile.name }}
                        <i v-if="tile.local && !calls.micOn" class="pi pi-microphone call-muted" />
                        <em v-if="tile.share">Screen</em>
                    </span>
                </div>
            </template>

            <p v-if="calls.connection === 'failed' && !waiting" class="call-stage-note">Can't reach them yet. Still trying…</p>
        </div>

        <div class="call-sinks" aria-hidden="true">
            <CallAudio
                v-for="person in remoteList"
                :key="person.user_id"
                :stream="calls.remoteAudio[person.user_id] || null"
                @blocked="soundBlocked = true"
                @playing="pumpAudio"
            />
        </div>

        <button v-if="soundBlocked" type="button" class="call-sound" @click.stop="pumpAudio">
            <i class="pi pi-volume-up" /> Tap to hear the {{ noun }}
        </button>

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
                severity="secondary"
                :aria-label="calls.cameraOn ? 'Turn camera off' : 'Turn camera on'"
                :class="{ 'call-off': !calls.cameraOn }"
                v-tooltip.top="calls.cameraOn ? 'Camera off' : 'Camera on'"
                @click="calls.toggleCamera()"
            />
            <Button
                v-if="canShare"
                icon="pi pi-desktop"
                rounded
                :severity="calls.sharing ? 'success' : 'secondary'"
                :aria-label="calls.sharing ? 'Stop sharing' : 'Share screen'"
                v-tooltip.top="calls.sharing ? 'Stop sharing' : 'Share screen'"
                @click="calls.toggleShare()"
            />
            <Button
                :label="calls.outgoing ? 'Cancel' : (isDm ? 'End' : 'Leave')"
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
        role="alertdialog"
        :aria-label="`Incoming ${kindLabel} ${noun} from ${ringName}`"
    >
        <div class="call-ring-card">
            <div class="call-ring-who">
                <p class="call-ring-app"><i :class="ringIcon" /> SmackChat {{ kindLabel }} {{ noun }}</p>
                <h2>{{ ringName }}</h2>
                <p class="call-ring-sub">{{ ringLine }}</p>
            </div>

            <div class="call-ring-avatar">
                <span class="call-ring-wave wave-one" />
                <span class="call-ring-wave wave-two" />
                <PersonAvatar :person="caller" :name="ringName" size="xlarge" />
            </div>

            <div class="call-ring-actions">
                <button
                    type="button"
                    class="call-ring-btn decline"
                    aria-label="Decline"
                    :disabled="calls.busy"
                    @click="calls.declineIncoming()"
                >
                    <span class="call-ring-icon"><i class="pi pi-phone" /></span>
                    <em aria-hidden="true">Decline</em>
                </button>
                <button
                    type="button"
                    class="call-ring-btn accept"
                    aria-label="Accept"
                    :disabled="calls.busy"
                    @click="calls.acceptIncoming()"
                >
                    <span class="call-ring-icon"><i :class="calls.busy ? 'pi pi-spin pi-spinner' : ringIcon" /></span>
                    <em aria-hidden="true">Accept</em>
                </button>
            </div>
        </div>
    </div>
</template>
