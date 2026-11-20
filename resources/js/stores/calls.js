import { defineStore } from 'pinia';
import { computed, ref, shallowRef } from 'vue';
import api from '@/api';
import { startRingtone, stopRingtone } from '@/lib/ringtone';
import { realtimeConnected } from '@/realtime';
import { useAuthStore } from '@/stores/auth';
import { useOfficeStore } from '@/stores/office';

const RING_WINDOW_MS = 60000;
const RING_TIMEOUT_MS = 45000;
const ANSWER_TIMEOUT_MS = 60000;
const SIGNAL_POLL_MS = 700;
const SYNC_MS = 1000;
const ANSWER_WAIT_MS = 8000;
const OFFER_WAIT_MS = 5000;
const DISCONNECT_GRACE_MS = 4000;

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function newSession() {
    return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;
}

function notice(message) {
    window.dispatchEvent(new CustomEvent('smack-call-notice', { detail: { message } }));
}

function iceServers() {
    const servers = useOfficeStore().workspace?.calls?.ice_servers || [];
    const list = servers.length ? servers : ['stun:stun.l.google.com:19302'];

    return list.map((server) => (typeof server === 'string' ? { urls: server } : server));
}

/**
 * SDP must end with CRLF. Laravel's TrimStrings middleware strips the final
 * CRLF from request strings, which makes Chrome reject the description with
 * "Invalid SDP line". Normalise on both send and receive.
 */
function description(payload) {
    const sdp = typeof payload?.sdp === 'string' ? payload.sdp : '';

    return {
        type: payload?.type,
        sdp: sdp.endsWith('\r\n') ? sdp : `${sdp}\r\n`,
    };
}

/**
 * WebRTC huddles. The server is only the switchboard: it stores SDP/ICE
 * handshakes and nudges the other side over Ably. Audio and video travel
 * straight between the people in the call.
 */
export const useCallStore = defineStore('calls', () => {
    const auth = useAuthStore();
    const office = useOfficeStore();

    const call = ref(null);
    const incoming = ref(null);
    const outgoing = ref(false);
    const activeCalls = ref([]);
    const participants = ref([]);
    const remoteStreams = ref({});
    const localStream = shallowRef(null);
    const connection = ref('idle');
    const error = ref('');
    const micOn = ref(true);
    const cameraOn = ref(false);
    const sharing = ref(false);
    const remoteSharing = ref({});
    const elapsed = ref(0);
    const busy = ref(false);

    const myId = computed(() => auth.user?.id ?? null);
    const inCall = computed(() => call.value !== null);
    const others = computed(() => participants.value.filter((person) => person.user_id !== myId.value));
    const callFor = computed(() => (channelId) => activeCalls.value.find((item) => item.channel_id === channelId) || null);

    let local = null;
    let cameraTrack = null;
    let screenTrack = null;
    let workspaceSlug = null;
    let joinedAt = 0;
    let lastSignalId = 0;
    let attached = false;
    let leaving = false;
    const dismissed = new Set();
    const peers = new Map();
    let signalTimer = null;
    let syncTimer = null;
    let ringTimer = null;
    let clockTimer = null;
    let signalBusy = false;
    let syncBusy = false;
    let refreshing = false;
    let incomingTimer = null;
    let outgoingTimer = null;
    // False until the microphone is open. A peer built before that has no
    // audio sender, and the other person never hears this side.
    let mediaReady = false;
    // Changes with every join. It rides on each signal so the other side can
    // tell a refreshed tab from the old one and rebuild its connection.
    let mySession = '';

    function attach() {
        if (attached || !office.slug) {
            return;
        }

        attached = true;
        window.addEventListener('smack-call', onRealtimeCall);
        window.addEventListener('pagehide', onPageHide);
        navigator.mediaDevices?.addEventListener?.('devicechange', onDeviceChange);
        refreshActive();
        joinFromLink();
        ringTimer = setInterval(refreshActive, realtimeConnected() ? 20000 : 10000);
        clockTimer = setInterval(tick, 1000);
    }

    function detach() {
        if (!attached) {
            return;
        }

        attached = false;
        window.removeEventListener('smack-call', onRealtimeCall);
        window.removeEventListener('pagehide', onPageHide);
        navigator.mediaDevices?.removeEventListener?.('devicechange', onDeviceChange);
        clearInterval(ringTimer);
        clearInterval(clockTimer);
        ringTimer = null;
        clockTimer = null;

        if (call.value) {
            leave();
        }

        clearIncoming();
        activeCalls.value = [];
    }

    async function refreshActive() {
        if (refreshing || !office.slug) {
            return;
        }

        refreshing = true;

        try {
            const { data } = await api.get(`/workspaces/${office.slug}/calls/active`);
            activeCalls.value = data.calls || [];
            maybeRing();
        } catch {
            /* the next pass retries */
        } finally {
            refreshing = false;
        }
    }

    function maybeRing() {
        if (call.value || incoming.value) {
            return;
        }

        const fresh = activeCalls.value.find((item) => {
            if (item.status !== 'live' || item.joined || item.started_by === myId.value) {
                return false;
            }

            if (dismissed.has(item.id)) {
                return false;
            }

            return Date.now() - Date.parse(item.started_at || 0) < RING_WINDOW_MS;
        });

        if (fresh) {
            ring(fresh);
        }
    }

    function ring(payload) {
        clearIncoming();
        incoming.value = payload;
        startRingtone();

        incomingTimer = setTimeout(() => {
            if (incoming.value?.id !== payload.id) {
                return;
            }

            const who = payload.participants?.find((person) => person.user_id === payload.started_by)?.name || 'Someone';
            dismissed.add(payload.id);
            clearIncoming();
            notice(`Missed ${payload.kind === 'video' ? 'video ' : ''}call from ${who}.`);
        }, RING_TIMEOUT_MS);
    }

    function clearIncoming() {
        stopRingtone();
        clearTimeout(incomingTimer);
        incomingTimer = null;
        incoming.value = null;
    }

    function startOutgoingTimer() {
        clearTimeout(outgoingTimer);
        outgoingTimer = setTimeout(() => {
            if (!outgoing.value) {
                return;
            }

            outgoing.value = false;
            notice('No answer.');
            leave();
        }, ANSWER_TIMEOUT_MS);
    }

    function noteParticipants() {
        if (participants.value.length > 1 && outgoing.value) {
            outgoing.value = false;
            clearTimeout(outgoingTimer);
            outgoingTimer = null;
        }
    }

    function onRealtimeCall(event) {
        const payload = event.detail || {};
        const id = payload.call_id;

        if (!id) {
            return;
        }

        if (payload.event === 'call.ring') {
            refreshActive();

            return;
        }

        if (payload.event === 'call.ended') {
            if (incoming.value?.id === id) {
                clearIncoming();
            }

            if (call.value?.id === id) {
                endLocally('The call ended.');
            }

            refreshActive();

            return;
        }

        if (payload.event === 'call.declined') {
            if (call.value?.id === id && Number(payload.user_id) !== Number(myId.value) && outgoing.value) {
                const who = activeCalls.value.find((item) => item.id === id)?.channel_label;
                notice(who ? `${who} declined the call.` : 'Call declined.');
            }

            return;
        }

        // Until the mic is open, leave signals on the server. The poll that
        // starts after openMedia() reads them with a working audio sender.
        if (call.value?.id !== id || !mediaReady) {
            return;
        }

        if (payload.event === 'call.signal') {
            // Every member hears every nudge; only fetch the ones meant for us.
            if (payload.to === undefined || Number(payload.to) === Number(myId.value)) {
                fetchSignals();
            }

            return;
        }

        if (payload.event === 'call.joined' && payload.rejoin && Number(payload.user_id) !== Number(myId.value)) {
            // They refreshed or rejoined. The old connection points at a tab
            // that is gone; start over with them.
            closePeer(payload.user_id);
        }

        syncCall();
    }

    /**
     * Free the seat when the tab closes or reloads, so the others drop the
     * dead connection at once instead of after the stale timeout.
     */
    function onPageHide(event) {
        if (!call.value || !workspaceSlug || event.persisted) {
            return;
        }

        try {
            fetch(`/api/workspaces/${workspaceSlug}/calls/${call.value.id}/leave`, {
                method: 'POST',
                credentials: 'include',
                keepalive: true,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            });
        } catch {
            /* the server prunes the seat later */
        }
    }

    async function start(channel, kind = 'audio') {
        if (!channel?.slug || busy.value) {
            return;
        }

        busy.value = true;
        error.value = '';

        try {
            const { data } = await api.post(`/workspaces/${office.slug}/calls`, {
                channel: channel.slug,
                kind,
            });

            await adopt(data.call, { outgoing: Boolean(data.created) });
        } catch (failure) {
            fail(failure);
        } finally {
            busy.value = false;
        }
    }

    /**
     * A call push opens /w/{slug}/c/{channel}?call={id}. Join that call once,
     * then drop the query so a refresh does not join it again.
     */
    function joinFromLink() {
        const url = new URL(window.location.href);
        const id = Number(url.searchParams.get('call'));

        if (!id) {
            return;
        }

        // On a cold load the SPA has not resolved the workspace yet. Keep the
        // query until the slug is ready, then attach() calls this again.
        if (!office.slug) {
            return;
        }

        const declining = url.searchParams.get('decline') === '1';
        url.searchParams.delete('call');
        url.searchParams.delete('decline');
        window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);

        if (declining) {
            decline(id);

            return;
        }

        if (!call.value && !busy.value) {
            joinById(id);
        }
    }

    async function decline(id, slug = office.slug) {
        dismissed.add(id);

        try {
            await api.post(`/workspaces/${slug}/calls/${id}/decline`);
        } catch {
            /* the caller's own timeout ends it */
        }
    }

    async function joinById(id) {
        if (!id || !office.slug || busy.value) {
            return;
        }

        busy.value = true;
        error.value = '';

        try {
            const { data } = await api.post(`/workspaces/${office.slug}/calls/${id}/join`);
            await adopt(data.call);
        } catch (failure) {
            fail(failure);
        } finally {
            busy.value = false;
        }
    }

    async function acceptIncoming() {
        const target = incoming.value;

        if (!target || busy.value) {
            return;
        }

        busy.value = true;
        error.value = '';

        try {
            const { data } = await api.post(`/workspaces/${office.slug}/calls/${target.id}/join`);
            await adopt(data.call);
        } catch (failure) {
            fail(failure);
        } finally {
            busy.value = false;
        }
    }

    function declineIncoming() {
        if (incoming.value) {
            const id = incoming.value.id;
            clearIncoming();
            decline(id);
        }
    }

    function fail(failure) {
        const message = failure?.response?.data?.message
            || failure?.response?.data?.errors?.call?.[0]
            || failure?.message
            || 'Could not start the call.';
        error.value = message;
        notice(message);
        cleanup();
    }

    function endLocally(message) {
        notice(message);
        cleanup();
    }

    async function adopt(payload, options = {}) {
        cleanup();
        clearIncoming();
        workspaceSlug = office.slug;
        call.value = payload;
        participants.value = payload.participants || [];
        outgoing.value = Boolean(options.outgoing) && participants.value.length <= 1;

        if (outgoing.value) {
            startOutgoingTimer();
        }

        dismissed.delete(payload.id);
        joinedAt = Date.now();
        lastSignalId = 0;
        mediaReady = false;
        mySession = newSession();
        connection.value = 'connecting';

        try {
            await openMedia(payload.kind);
        } catch (failure) {
            error.value = failure?.name === 'NotAllowedError'
                ? 'Microphone or camera permission was blocked.'
                : (failure?.message || 'Could not open your microphone.');
            notice(error.value);
            const slug = workspaceSlug;
            // Clear local state first so the "call ended" nudge that our own
            // leave triggers does not raise a second message.
            cleanup();
            await leaveServer(payload.id, slug);

            return;
        }

        // The call may have ended or been left while the permission prompt
        // was open.
        if (call.value?.id !== payload.id) {
            local?.getTracks().forEach((track) => track.stop());
            local = null;

            return;
        }

        mediaReady = true;
        connectPeers();
        connection.value = 'connecting';
        startTimers();
        tick();
    }

    /**
     * Rebuild the preview stream object so Vue rebinds the local <video> when a
     * camera or screen track is added or removed.
     */
    function refreshPreview() {
        if (!local) {
            localStream.value = null;

            return;
        }

        // While sharing, the local tile should show what the room sees.
        const tracks = sharing.value && screenTrack
            ? [...local.getAudioTracks(), screenTrack]
            : local.getTracks();

        localStream.value = new MediaStream(tracks);
    }

    async function openMedia(kind) {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error('This browser cannot make calls. Try Chrome, Edge, or Safari over HTTPS.');
        }

        const audio = {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
        };

        if (kind === 'video') {
            try {
                local = await navigator.mediaDevices.getUserMedia({
                    audio,
                    video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
                });
                cameraTrack = local.getVideoTracks()[0] || null;
                cameraOn.value = Boolean(cameraTrack);
            } catch {
                local = await navigator.mediaDevices.getUserMedia({ audio });
                cameraOn.value = false;
            }
        } else {
            local = await navigator.mediaDevices.getUserMedia({ audio });
            cameraOn.value = false;
        }

        micOn.value = local.getAudioTracks().every((track) => track.enabled);
        watchMic();
        refreshPreview();
    }

    /**
     * A Bluetooth headset connecting, a USB mic unplugged, or iOS taking the
     * mic for the camera ends the audio track. Without a new one this side
     * goes silent and nobody is told.
     */
    function watchMic() {
        const track = local?.getAudioTracks()[0];

        if (track) {
            track.addEventListener('ended', () => reopenMic(track), { once: true });
        }
    }

    async function reopenMic(dead) {
        if (!local || !call.value || (dead && !local.getAudioTracks().includes(dead))) {
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
            });
            const track = stream.getAudioTracks()[0];

            if (!track || !local) {
                track?.stop();

                return;
            }

            local.getAudioTracks().forEach((old) => {
                local.removeTrack(old);

                if (old !== track) {
                    old.stop();
                }
            });
            track.enabled = micOn.value;
            local.addTrack(track);
            watchMic();

            await Promise.all([...peers.values()].map(async (state) => {
                const sender = audioTransceiver(state.pc)?.sender;

                if (sender) {
                    await sender.replaceTrack(track).catch(() => {});
                }
            }));

            refreshPreview();
        } catch {
            notice('Your microphone stopped. Check it and rejoin the call.');
        }
    }

    function onDeviceChange() {
        const track = local?.getAudioTracks()[0];

        if (call.value && track && track.readyState === 'ended') {
            reopenMic(track);
        }
    }

    function connectPeers() {
        if (!mediaReady) {
            return;
        }

        for (const person of others.value) {
            const state = ensurePeer(person.user_id);
            attachLocalAudio(state);
        }
    }

    function audioTransceiver(pc) {
        return pc.getTransceivers().find((item) => {
            const kind = item.sender?.track?.kind || item.receiver?.track?.kind;

            return kind === 'audio';
        }) || null;
    }

    /**
     * Backstop for a peer built before the mic was open: put the mic on its
     * audio sender and renegotiate so the other side starts hearing us.
     */
    function attachLocalAudio(state) {
        const audio = local?.getAudioTracks()[0];
        const pc = state?.pc;

        if (!audio || !pc || pc.signalingState === 'closed') {
            return;
        }

        if (pc.getSenders().some((sender) => sender.track?.kind === 'audio')) {
            return;
        }

        const transceiver = audioTransceiver(pc);

        if (!transceiver) {
            pc.addTrack(audio, local);

            return;
        }

        enqueue(state, async () => {
            await transceiver.sender.replaceTrack(audio);
            transceiver.sender.setStreams?.(local);

            if (transceiver.direction !== 'sendrecv') {
                // Changing the direction fires negotiationneeded, which sends a
                // fresh offer (or asks the offerer for one).
                transceiver.direction = 'sendrecv';
            }
        });
    }

    /**
     * The lower user id is the only side that creates offers. Both sides used
     * to offer at once, and the rollback that was meant to break the tie ran
     * while the connection was still stable, so Chrome threw and both peers
     * stayed stuck with an offer nobody answered. Audio and video never started.
     */
    function isOfferer(peerId) {
        return Number(myId.value) < Number(peerId);
    }

    function enqueue(state, job) {
        state.chain = state.chain.then(job).catch((error) => {
            console.error('[huddle] negotiation failed', error);
        });

        return state.chain;
    }

    function videoTransceiver(pc) {
        return pc.getTransceivers().find((item) => {
            const kind = item.sender?.track?.kind || item.receiver?.track?.kind;

            return kind === 'video';
        }) || null;
    }

    function outgoingVideoTrack() {
        return screenTrack || cameraTrack || null;
    }

    /**
     * Only the offerer creates the video m-line. On the answerer the browser
     * builds it from the offer; a transceiver added here beforehand would sit
     * unused, and the camera put on it never reached the other side.
     */
    async function armVideo(pc) {
        let transceiver = videoTransceiver(pc);

        if (!transceiver) {
            if (!pc.__offerer) {
                return null;
            }

            transceiver = pc.addTransceiver('video', { direction: 'sendrecv' });
        }

        if (transceiver.direction !== 'sendrecv') {
            transceiver.direction = 'sendrecv';
        }

        const video = outgoingVideoTrack();

        if (transceiver.sender.track !== video) {
            await transceiver.sender.replaceTrack(video);
        }

        return transceiver;
    }

    function publishRemote(peerId, stream) {
        remoteStreams.value = { ...remoteStreams.value, [peerId]: stream };

        if (outgoing.value) {
            outgoing.value = false;
            clearTimeout(outgoingTimer);
            outgoingTimer = null;
        }
    }

    function updateConnection() {
        const states = [...peers.values()].map((item) => item.pc.iceConnectionState);

        if (!call.value) {
            connection.value = 'idle';

            return;
        }

        if (states.some((item) => item === 'connected' || item === 'completed')) {
            connection.value = 'live';
            error.value = '';

            return;
        }

        if (states.length && states.every((item) => item === 'failed' || item === 'disconnected')) {
            connection.value = 'failed';

            return;
        }

        connection.value = 'connecting';
    }

    function clearPeerTimers(state) {
        clearTimeout(state.answerTimer);
        clearTimeout(state.offerTimer);
        clearTimeout(state.disconnectTimer);
        state.answerTimer = null;
        state.offerTimer = null;
        state.disconnectTimer = null;
    }

    function closePeer(peerId, { keepStream = false } = {}) {
        const key = Number(peerId);
        const state = peers.get(key);

        if (!state) {
            return;
        }

        clearPeerTimers(state);
        state.closed = true;

        try {
            state.pc.close();
        } catch {
            /* already closed */
        }

        peers.delete(key);

        if (!keepStream) {
            const next = { ...remoteStreams.value };
            delete next[key];
            remoteStreams.value = next;
        }

        updateConnection();
    }

    /**
     * Send an offer now. Used for the first handshake, video changes, ICE
     * restarts, and when an offer went unanswered.
     */
    async function offer(state, key, { iceRestart = false } = {}) {
        const pc = state.pc;

        if (state.closed || pc.signalingState !== 'stable') {
            return;
        }

        state.makingOffer = true;

        try {
            await armVideo(pc);

            if (pc.signalingState !== 'stable') {
                return;
            }

            if (iceRestart) {
                await pc.setLocalDescription(await pc.createOffer({ iceRestart: true }));
            } else {
                await pc.setLocalDescription();
            }

            await sendSignal(key, 'offer', pc.localDescription);
            watchAnswer(state, key);
        } finally {
            state.makingOffer = false;
        }
    }

    /**
     * An offer whose answer was lost (rate limit, flaky network, a tab that
     * reloaded) would leave this pair silent forever. Roll back and re-offer.
     */
    function watchAnswer(state, key) {
        clearTimeout(state.answerTimer);
        state.answerTimer = setTimeout(() => {
            if (state.closed || state.pc.signalingState !== 'have-local-offer') {
                return;
            }

            enqueue(state, async () => {
                if (state.closed || state.pc.signalingState !== 'have-local-offer') {
                    return;
                }

                await state.pc.setLocalDescription({ type: 'rollback' });
                await offer(state, key);
            });
        }, ANSWER_WAIT_MS);
    }

    /**
     * The answerer cannot start a handshake. It asks the offerer for one, and
     * asks again if nothing arrives, which covers a lost first offer and an
     * offerer still holding a connection to this tab's previous session.
     */
    function askForOffer(state, key, attempt = 0) {
        clearTimeout(state.offerTimer);
        sendSignal(key, 'refresh', { ask: 1, hello: 1 });

        if (attempt >= 5) {
            return;
        }

        state.offerTimer = setTimeout(() => {
            if (!state.closed && !state.pc.remoteDescription) {
                askForOffer(state, key, attempt + 1);
            }
        }, OFFER_WAIT_MS);
    }

    function ensurePeer(peerId) {
        const key = Number(peerId);

        if (peers.has(key)) {
            return peers.get(key);
        }

        const pc = new RTCPeerConnection({
            iceServers: iceServers(),
            bundlePolicy: 'max-bundle',
        });
        pc.__offerer = isOfferer(key);
        const state = {
            pc,
            makingOffer: false,
            ignoreOffer: false,
            polite: Number(myId.value) > key,
            pending: [],
            stream: new MediaStream(),
            chain: Promise.resolve(),
            lastRestart: 0,
            remoteSession: '',
            // Ids of this connection and of the one it is paired with. An
            // offer or hello from a different remote connection means the
            // other side started over, and so must we.
            connId: newSession(),
            remoteConn: '',
            answerTimer: null,
            offerTimer: null,
            disconnectTimer: null,
            closed: false,
        };
        peers.set(key, state);

        const audio = local?.getAudioTracks()[0];

        if (audio) {
            pc.addTrack(audio, local);
        } else {
            // Keep an audio m-line either way so a late mic can be attached
            // with replaceTrack (see attachLocalAudio).
            pc.addTransceiver('audio', { direction: 'sendrecv' });
        }

        // A video m-line from the start lets camera and screen share swap with
        // replaceTrack instead of a second handshake that can fail silently.
        armVideo(pc).catch((error) => console.error('[huddle] video setup failed', error));

        pc.onicecandidate = (event) => {
            if (event.candidate) {
                sendSignal(key, 'candidate', event.candidate.toJSON());
            }
        };

        pc.ontrack = (event) => {
            const stream = event.streams?.[0] || state.stream;

            if (!stream.getTracks().includes(event.track)) {
                stream.addTrack(event.track);
            }

            state.stream = stream;
            publishRemote(key, stream);
            const refresh = () => publishRemote(key, stream);
            event.track.addEventListener('unmute', refresh);
            event.track.addEventListener('mute', refresh);
            event.track.addEventListener('ended', refresh);
        };

        pc.onnegotiationneeded = () => {
            enqueue(state, async () => {
                if (!local || !call.value || state.closed || pc.signalingState !== 'stable') {
                    return;
                }

                if (!isOfferer(key)) {
                    if (pc.remoteDescription && await senderNeedsOffer(pc)) {
                        await sendSignal(key, 'refresh', { ask: 1 });
                    }

                    return;
                }

                // An offer is already sitting unanswered. Sending another one
                // here is what left both sides waiting on each other.
                if (pc.localDescription && !pc.currentRemoteDescription) {
                    return;
                }

                try {
                    await offer(state, key);
                } catch (error) {
                    console.error('[huddle] offer failed', error);
                }
            });
        };

        if (sharing.value) {
            sendSignal(key, 'status', { sharing: true });
        }

        pc.oniceconnectionstatechange = () => {
            updateConnection();
            const ice = pc.iceConnectionState;

            if (ice === 'connected' || ice === 'completed') {
                clearTimeout(state.disconnectTimer);
                state.disconnectTimer = null;

                return;
            }

            if (ice === 'disconnected' && !state.disconnectTimer) {
                // Chrome waits 15-30 seconds before calling this "failed".
                // Restart sooner so a Wi-Fi to mobile data switch heals fast.
                state.disconnectTimer = setTimeout(() => {
                    state.disconnectTimer = null;

                    if (!state.closed && pc.iceConnectionState === 'disconnected') {
                        restartIce(state, key);
                    }
                }, DISCONNECT_GRACE_MS);

                return;
            }

            if (ice === 'failed') {
                restartIce(state, key);
            }
        };

        pc.onconnectionstatechange = () => {
            if (pc.connectionState !== 'failed' || state.closed || !call.value) {
                return;
            }

            // An ICE restart did not help. Start a brand-new connection; the
            // other side rebuilds when our next offer does not fit its old one.
            if (isOfferer(key) && Date.now() - state.lastRestart > 3000) {
                closePeer(key, { keepStream: true });
                connectPeers();
            }
        };

        if (!isOfferer(key) && local) {
            askForOffer(state, key);
        }

        return state;
    }

    function restartIce(state, key) {
        const now = Date.now();

        if (state.closed || now - state.lastRestart < 5000) {
            return;
        }

        state.lastRestart = now;

        if (!isOfferer(key)) {
            sendSignal(key, 'refresh', { ask: 1, restart: 1 });

            return;
        }

        enqueue(state, async () => {
            if (state.pc.signalingState === 'have-local-offer') {
                await state.pc.setLocalDescription({ type: 'rollback' });
            }

            await offer(state, key, { iceRestart: true });
        });
    }

    async function senderNeedsOffer(pc) {
        await armVideo(pc);

        return pc.getTransceivers().some((item) => {
            if (!item.sender.track || item.direction !== 'sendrecv') {
                return false;
            }

            const current = item.currentDirection;

            return current === 'recvonly' || current === 'inactive';
        });
    }

    function handleSignal(from, type, payload) {
        const key = Number(from);
        const session = typeof payload?._s === 'string' ? payload._s : '';
        const conn = typeof payload?._c === 'string' ? payload._c : '';
        const startsOver = type === 'offer' || (type === 'refresh' && payload?.hello);
        let state = peers.get(key);

        // A signal from a newer session of that person means their tab was
        // reloaded; an offer or hello from another connection means they
        // rebuilt it. Our connection belongs to the old one: replace it.
        const staleSession = session && state?.remoteSession && state.remoteSession !== session;
        const staleConn = startsOver && conn && state?.remoteConn && state.remoteConn !== conn;

        if (state && (staleSession || staleConn)) {
            closePeer(key, { keepStream: true });
            state = null;
        }

        state = state || ensurePeer(key);

        if (session && !state.remoteSession) {
            state.remoteSession = session;
        }

        if (conn && (type === 'offer' || type === 'answer' || (type === 'refresh' && payload?.hello))) {
            state.remoteConn = conn;
        }

        return enqueue(state, () => applySignal(state, from, type, payload));
    }

    function stripSession(payload) {
        if (!payload || typeof payload !== 'object') {
            return payload;
        }

        const { _s: _session, _c: _conn, ...rest } = payload;

        return rest;
    }

    async function applySignal(state, from, type, raw) {
        const pc = state.pc;
        const key = Number(from);
        const payload = stripSession(raw);

        if (state.closed) {
            return;
        }

        if (type === 'status') {
            remoteSharing.value = {
                ...remoteSharing.value,
                [key]: Boolean(payload?.sharing),
            };

            return;
        }

        if (type === 'refresh') {
            if (!isOfferer(key)) {
                return;
            }

            if (payload?.restart) {
                if (pc.signalingState === 'stable') {
                    state.lastRestart = Date.now();
                    await offer(state, key, { iceRestart: true });
                }

                return;
            }

            // A hello from a tab that has a connection with us already: our
            // offer reached it or will; the answer watchdog covers a loss.
            if (payload?.hello && pc.signalingState === 'have-local-offer') {
                return;
            }

            if (pc.signalingState === 'stable') {
                await offer(state, key);
            }

            return;
        }

        if (type === 'candidate') {
            if (state.ignoreOffer) {
                return;
            }

            if (!pc.remoteDescription) {
                state.pending.push(payload);

                return;
            }

            try {
                await pc.addIceCandidate(payload);
            } catch {
                // After an ICE restart a candidate can beat its offer. Keep it
                // for when the new description lands.
                state.pending.push(payload);
            }

            return;
        }

        if (type !== 'offer' && type !== 'answer') {
            return;
        }

        if (!payload?.sdp) {
            return;
        }

        const collision = type === 'offer' && (state.makingOffer || pc.signalingState !== 'stable');

        if (!state.polite && collision) {
            state.ignoreOffer = true;
            state.pending = [];

            return;
        }

        state.ignoreOffer = false;

        if (type === 'answer' && pc.signalingState !== 'have-local-offer') {
            // A late answer to an offer we already rolled back.
            return;
        }

        try {
            await pc.setRemoteDescription(description(payload));
        } catch (error) {
            if (state.polite && type === 'offer' && pc.signalingState === 'have-local-offer') {
                await pc.setLocalDescription({ type: 'rollback' });
                await pc.setRemoteDescription(description(payload));
            } else if (type === 'offer') {
                // The offer comes from a different connection than the one we
                // hold (the other side rebuilt). Start fresh and accept it.
                console.warn('[huddle] rebuilding connection after a mismatched offer', error?.message);
                const { remoteSession, remoteConn } = state;
                closePeer(key, { keepStream: true });
                const fresh = ensurePeer(key);
                fresh.remoteSession = remoteSession;
                fresh.remoteConn = remoteConn;
                clearTimeout(fresh.offerTimer);
                await applySignal(fresh, from, type, payload);

                return;
            } else {
                console.error('[huddle] signal failed', type, error);

                return;
            }
        }

        if (type === 'answer') {
            clearTimeout(state.answerTimer);
            state.answerTimer = null;
        }

        if (type === 'offer') {
            clearTimeout(state.offerTimer);
            state.offerTimer = null;
        }

        for (const candidate of state.pending.splice(0)) {
            await pc.addIceCandidate(candidate).catch(() => {});
        }

        if (type === 'offer') {
            await armVideo(pc);
            await pc.setLocalDescription();
            await sendSignal(key, 'answer', pc.localDescription);
        }
    }

    async function sendSignal(to, type, payload) {
        if (!call.value || !workspaceSlug) {
            return;
        }

        if ((type === 'offer' || type === 'answer') && !payload?.sdp) {
            return;
        }

        const id = call.value.id;
        const slug = workspaceSlug;
        const base = (type === 'offer' || type === 'answer') ? description(payload) : { ...payload };
        const body = {
            to: Number(to),
            type,
            payload: { ...base, _s: mySession, _c: peers.get(Number(to))?.connId || '' },
        };

        let lastError = null;
        const deadline = Date.now() + 6000;

        for (let attempt = 0; Date.now() < deadline; attempt += 1) {
            if (call.value?.id !== id) {
                return;
            }

            try {
                await api.post(`/workspaces/${slug}/calls/${id}/signal`, body);

                return;
            } catch (error) {
                lastError = error;
                const status = error?.response?.status;

                if (status === 403 || status === 404 || status === 410 || status === 422) {
                    break;
                }

                const retryAfter = Number(error?.response?.headers?.['retry-after']) * 1000;
                const wait = retryAfter > 0 ? Math.min(retryAfter, 3000) : Math.min(250 * 2 ** attempt, 2000);
                await sleep(wait);
            }
        }

        console.error('[huddle] signal was not delivered', type, lastError?.response?.status || lastError?.message);
    }

    async function fetchSignals() {
        if (!call.value || !mediaReady || signalBusy || !workspaceSlug) {
            return;
        }

        signalBusy = true;

        try {
            const { data } = await api.get(`/workspaces/${workspaceSlug}/calls/${call.value.id}/signals`, {
                params: { after: lastSignalId },
            });

            for (const signal of data.signals || []) {
                lastSignalId = Math.max(lastSignalId, signal.id);
                await handleSignal(signal.from, signal.type, signal.payload);
            }
        } catch {
            /* retry on the next tick */
        } finally {
            signalBusy = false;
        }
    }

    async function syncCall() {
        if (!call.value || !mediaReady || syncBusy || !workspaceSlug) {
            return;
        }

        syncBusy = true;

        try {
            const { data } = await api.get(`/workspaces/${workspaceSlug}/calls/${call.value.id}`);
            const next = data.call;

            if (!next || next.status === 'ended') {
                endLocally('The huddle ended.');

                return;
            }

            call.value = next;
            participants.value = next.participants || [];
            noteParticipants();
            prunePeers();
            connectPeers();
            updateConnection();
        } catch {
            /* keep the last known roster */
        } finally {
            syncBusy = false;
        }
    }

    function prunePeers() {
        const seated = new Set(others.value.map((person) => Number(person.user_id)));

        for (const peerId of [...peers.keys()]) {
            if (!seated.has(peerId)) {
                closePeer(peerId);
                const sharingNext = { ...remoteSharing.value };
                delete sharingNext[peerId];
                remoteSharing.value = sharingNext;
            }
        }
    }

    function startTimers() {
        clearInterval(signalTimer);
        clearInterval(syncTimer);
        signalTimer = setInterval(fetchSignals, SIGNAL_POLL_MS);
        syncTimer = setInterval(syncCall, SYNC_MS);
        fetchSignals();
    }

    function stopTimers() {
        clearInterval(signalTimer);
        clearInterval(syncTimer);
        signalTimer = null;
        syncTimer = null;
        signalBusy = false;
        syncBusy = false;
    }

    function tick() {
        elapsed.value = joinedAt ? Math.max(0, Math.floor((Date.now() - joinedAt) / 1000)) : 0;
    }

    async function leaveServer(id, slug = workspaceSlug) {
        if (!id || !slug) {
            return;
        }

        try {
            await api.post(`/workspaces/${slug}/calls/${id}/leave`);
        } catch {
            /* the stale participant is pruned by the server */
        }
    }

    async function leave() {
        if (leaving) {
            return;
        }

        leaving = true;
        const id = call.value?.id;
        const slug = workspaceSlug;
        cleanup();
        await leaveServer(id, slug);
        leaving = false;
        refreshActive();
    }

    function cleanup() {
        stopTimers();

        for (const state of peers.values()) {
            clearPeerTimers(state);
            state.closed = true;

            try {
                state.pc.close();
            } catch {
                /* already closed */
            }
        }

        peers.clear();
        mediaReady = false;
        mySession = '';
        remoteStreams.value = {};
        remoteSharing.value = {};

        if (screenTrack) {
            screenTrack.stop();
            screenTrack = null;
        }

        if (local) {
            local.getTracks().forEach((track) => track.stop());
        }

        local = null;
        cameraTrack = null;
        localStream.value = null;
        call.value = null;
        participants.value = [];
        connection.value = 'idle';
        cameraOn.value = false;
        micOn.value = true;
        sharing.value = false;
        elapsed.value = 0;
        joinedAt = 0;
        workspaceSlug = null;
        lastSignalId = 0;
        outgoing.value = false;
        clearTimeout(outgoingTimer);
        outgoingTimer = null;
    }

    function toggleMic() {
        micOn.value = !micOn.value;
        local?.getAudioTracks().forEach((track) => {
            track.enabled = micOn.value;
        });
    }

    async function applyOutgoingVideo() {
        await Promise.all([...peers.values()].map(async (state) => {
            await armVideo(state.pc);
        }));

        // replaceTrack is not enough when the m-line was negotiated without a
        // camera. Ask for a fresh offer so the other side actually receives it.
        for (const [peerId, state] of peers.entries()) {
            if (state.pc.signalingState !== 'stable') {
                continue;
            }

            if (isOfferer(peerId)) {
                enqueue(state, () => offer(state, peerId));

                continue;
            }

            if (state.pc.remoteDescription) {
                await sendSignal(peerId, 'refresh', { ask: 1 });
            }
        }
    }

    async function toggleCamera() {
        if (!call.value) {
            return;
        }

        if (cameraOn.value) {
            const track = cameraTrack;
            cameraTrack = null;
            cameraOn.value = false;

            if (track) {
                local?.removeTrack(track);
                track.stop();
            }

            if (!sharing.value) {
                await applyOutgoingVideo();
            }

            refreshPreview();

            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
            });
            const track = stream.getVideoTracks()[0];

            if (!track) {
                return;
            }

            cameraTrack = track;
            cameraOn.value = true;
            track.onended = () => {
                if (cameraTrack === track) {
                    cameraTrack = null;
                    cameraOn.value = false;
                    refreshPreview();
                }
            };

            if (!local.getTracks().includes(track)) {
                local.addTrack(track);
            }

            if (!sharing.value) {
                await applyOutgoingVideo();
            }

            refreshPreview();
        } catch {
            notice('Could not turn on the camera.');
        }
    }

    async function toggleShare() {
        if (!call.value) {
            return;
        }

        if (sharing.value) {
            stopSharing();

            return;
        }

        if (!navigator.mediaDevices?.getDisplayMedia) {
            notice('This browser cannot share a screen.');

            return;
        }

        try {
            const stream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
            const track = stream.getVideoTracks()[0];

            if (!track) {
                return;
            }

            track.contentHint = 'detail';
            screenTrack = track;
            sharing.value = true;
            announceShare(true);
            track.onended = () => {
                if (sharing.value) {
                    stopSharing();
                }
            };

            await applyOutgoingVideo();
            refreshPreview();
        } catch {
            /* the picker was closed */
        }
    }

    function announceShare(active) {
        for (const peerId of peers.keys()) {
            sendSignal(peerId, 'status', { sharing: active });
        }
    }

    function stopSharing() {
        const track = screenTrack;
        screenTrack = null;
        sharing.value = false;
        announceShare(false);

        if (track) {
            track.onended = null;
            track.stop();
        }

        applyOutgoingVideo()
            .catch((error) => console.error('[huddle] stop share failed', error))
            .finally(refreshPreview);
    }

    return {
        call,
        incoming,
        outgoing,
        activeCalls,
        participants,
        remoteStreams,
        localStream,
        connection,
        error,
        micOn,
        cameraOn,
        sharing,
        remoteSharing,
        elapsed,
        busy,
        inCall,
        others,
        callFor,
        attach,
        detach,
        refreshActive,
        joinFromLink,
        joinById,
        start,
        acceptIncoming,
        declineIncoming,
        leave,
        toggleMic,
        toggleCamera,
        toggleShare,
    };
});
