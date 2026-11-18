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
const SIGNAL_POLL_MS = 900;
const SYNC_MS = 5000;

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

    function attach() {
        if (attached || !office.slug) {
            return;
        }

        attached = true;
        window.addEventListener('smack-call', onRealtimeCall);
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

        if (call.value?.id === id) {
            if (payload.event === 'call.signal') {
                fetchSignals();
            } else {
                syncCall();
            }
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

        url.searchParams.delete('call');
        window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);

        if (!call.value && !busy.value) {
            joinById(id);
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
            dismissed.add(incoming.value.id);
            clearIncoming();
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
        connection.value = 'connecting';

        try {
            await openMedia(payload.kind);
        } catch (failure) {
            error.value = failure?.name === 'NotAllowedError'
                ? 'Microphone or camera permission was blocked.'
                : (failure?.message || 'Could not open your microphone.');
            notice(error.value);
            await leaveServer(payload.id, workspaceSlug);
            cleanup();

            return;
        }

        connectPeers();
        connection.value = 'live';
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
        refreshPreview();
    }

    function connectPeers() {
        for (const person of others.value) {
            ensurePeer(person.user_id);
        }
    }

    function ensurePeer(peerId) {
        if (peers.has(peerId)) {
            return peers.get(peerId);
        }

        const pc = new RTCPeerConnection({ iceServers: iceServers() });
        const state = {
            pc,
            makingOffer: false,
            polite: String(myId.value) > String(peerId),
            pending: [],
            stream: new MediaStream(),
        };
        peers.set(peerId, state);

        if (local) {
            for (const track of local.getTracks()) {
                pc.addTrack(track, local);
            }
        }

        pc.onicecandidate = (event) => {
            if (event.candidate) {
                sendSignal(peerId, 'candidate', event.candidate.toJSON());
            }
        };

        pc.ontrack = (event) => {
            state.stream.addTrack(event.track);
            remoteStreams.value = { ...remoteStreams.value, [peerId]: state.stream };
        };

        pc.onnegotiationneeded = async () => {
            if (!local || !call.value) {
                return;
            }

            try {
                state.makingOffer = true;
                await pc.setLocalDescription();
                await sendSignal(peerId, 'offer', pc.localDescription);
            } catch (error) {
                console.error('[huddle] offer failed', error);
            } finally {
                state.makingOffer = false;
            }
        };

        pc.onconnectionstatechange = () => {
            if (['failed', 'closed'].includes(pc.connectionState)) {
                pc.restartIce?.();
            }
        };

        return state;
    }

    async function handleSignal(from, type, payload) {
        const state = ensurePeer(from);
        const pc = state.pc;

        if (type === 'candidate') {
            if (!pc.remoteDescription) {
                state.pending.push(payload);

                return;
            }

            try {
                await pc.addIceCandidate(payload);
            } catch {
                /* a candidate can arrive after its peer left */
            }

            return;
        }

        if (!payload?.type) {
            return;
        }

        const collision = payload.type === 'offer' && (state.makingOffer || pc.signalingState !== 'stable');

        if (!state.polite && collision) {
            return;
        }

        try {
            if (collision) {
                await pc.setLocalDescription({ type: 'rollback' });
            }

            await pc.setRemoteDescription(description(payload));

            for (const candidate of state.pending.splice(0)) {
                await pc.addIceCandidate(candidate).catch(() => {});
            }

            if (payload.type === 'offer') {
                await pc.setLocalDescription();
                await sendSignal(from, 'answer', pc.localDescription);
            }
        } catch (error) {
            console.error('[huddle] signal failed', type, payload?.type, error);
        }
    }

    async function sendSignal(to, type, payload) {
        if (!call.value || !workspaceSlug) {
            return;
        }

        const body = type === 'candidate'
            ? { to, type, payload }
            : { to, type, payload: description(payload) };

        try {
            await api.post(`/workspaces/${workspaceSlug}/calls/${call.value.id}/signal`, body);
        } catch {
            /* polling still catches what matters */
        }
    }

    async function fetchSignals() {
        if (!call.value || signalBusy || !workspaceSlug) {
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
        if (!call.value || syncBusy || !workspaceSlug) {
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
        } catch {
            /* keep the last known roster */
        } finally {
            syncBusy = false;
        }
    }

    function prunePeers() {
        const seated = new Set(others.value.map((person) => person.user_id));

        for (const [peerId, state] of peers.entries()) {
            if (!seated.has(peerId)) {
                state.pc.close();
                peers.delete(peerId);
                const next = { ...remoteStreams.value };
                delete next[peerId];
                remoteStreams.value = next;
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
            try {
                state.pc.close();
            } catch {
                /* already closed */
            }
        }

        peers.clear();
        remoteStreams.value = {};

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

    async function toggleCamera() {
        if (!call.value) {
            return;
        }

        if (cameraOn.value) {
            const track = cameraTrack || local?.getVideoTracks()[0];

            if (track) {
                peers.forEach((state) => {
                    const sender = state.pc.getSenders().find((item) => item.track === track);

                    if (sender) {
                        state.pc.removeTrack(sender);
                    }
                });
                track.stop();
                local?.removeTrack(track);
            }

            cameraTrack = null;
            cameraOn.value = false;
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
            track.onended = () => {
                if (cameraOn.value) {
                    toggleCamera();
                }
            };

            local.addTrack(track);

            if (!sharing.value) {
                peers.forEach((state) => state.pc.addTrack(track, local));
            }

            cameraOn.value = true;
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
            const stream = await navigator.mediaDevices.getDisplayMedia({ video: true });
            const track = stream.getVideoTracks()[0];

            if (!track) {
                return;
            }

            screenTrack = track;
            sharing.value = true;

            let replaced = false;

            peers.forEach((state) => {
                const sender = state.pc
                    .getSenders()
                    .find((item) => item.track && item.track.kind === 'video' && item.track !== screenTrack);

                if (sender) {
                    sender.replaceTrack(track);
                    replaced = true;
                }
            });

            if (!replaced) {
                local.addTrack(track);
                peers.forEach((state) => state.pc.addTrack(track, local));
            }

            refreshPreview();

            track.onended = () => {
                if (sharing.value) {
                    stopSharing();
                }
            };
        } catch {
            /* the picker was closed */
        }
    }

    function stopSharing() {
        if (!screenTrack) {
            sharing.value = false;

            return;
        }

        peers.forEach((state) => {
            const sender = state.pc.getSenders().find((item) => item.track === screenTrack);

            if (sender) {
                sender.replaceTrack(cameraTrack || null);
            }
        });

        local?.removeTrack(screenTrack);
        screenTrack.stop();
        screenTrack = null;
        sharing.value = false;
        refreshPreview();
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
