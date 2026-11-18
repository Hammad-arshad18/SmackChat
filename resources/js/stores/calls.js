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
        refreshPreview();
    }

    function connectPeers() {
        for (const person of others.value) {
            ensurePeer(person.user_id);
        }
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

    async function armVideo(pc) {
        let transceiver = pc.__video || videoTransceiver(pc);

        if (!transceiver) {
            transceiver = pc.addTransceiver('video', { direction: 'sendrecv' });
            pc.__video = transceiver;
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

    function ensurePeer(peerId) {
        const key = Number(peerId);

        if (peers.has(key)) {
            return peers.get(key);
        }

        const pc = new RTCPeerConnection({
            iceServers: iceServers(),
            bundlePolicy: 'max-bundle',
        });
        const state = {
            pc,
            makingOffer: false,
            ignoreOffer: false,
            polite: Number(myId.value) > key,
            pending: [],
            stream: new MediaStream(),
            chain: Promise.resolve(),
            lastRestart: 0,
        };
        peers.set(key, state);

        const audio = local?.getAudioTracks()[0];

        if (audio) {
            pc.addTrack(audio, local);
        }

        // A video m-line from the start lets camera and screen share swap with
        // replaceTrack instead of a second handshake that can fail silently.
        pc.__video = pc.addTransceiver('video', { direction: 'sendrecv' });
        armVideo(pc).catch((error) => console.error('[huddle] video setup failed', error));

        pc.onicecandidate = (event) => {
            if (event.candidate) {
                sendSignal(key, 'candidate', event.candidate.toJSON());
            }
        };

        pc.ontrack = (event) => {
            const stream = event.streams?.[0] || state.stream;

            if (!event.streams?.[0]) {
                if (!stream.getTracks().includes(event.track)) {
                    stream.addTrack(event.track);
                }
            }

            state.stream = stream;
            publishRemote(key, stream);
            event.track.onunmute = () => publishRemote(key, stream);
            event.track.onmute = () => publishRemote(key, stream);
            event.track.onended = () => publishRemote(key, stream);
        };

        pc.onnegotiationneeded = () => {
            enqueue(state, async () => {
                if (!local || !call.value || pc.signalingState !== 'stable') {
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

                state.makingOffer = true;

                try {
                    await armVideo(pc);
                    if (pc.signalingState !== 'stable') {
                        return;
                    }

                    await pc.setLocalDescription();
                    await sendSignal(key, 'offer', pc.localDescription);
                } catch (error) {
                    console.error('[huddle] offer failed', error);
                } finally {
                    state.makingOffer = false;
                }
            });
        };

        pc.oniceconnectionstatechange = () => {
            updateConnection();

            if (pc.iceConnectionState !== 'failed' || !isOfferer(key)) {
                return;
            }

            const now = Date.now();

            if (now - state.lastRestart < 5000) {
                return;
            }

            state.lastRestart = now;
            pc.restartIce();
        };

        return state;
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
        const state = ensurePeer(from);

        return enqueue(state, () => applySignal(state, from, type, payload));
    }

    async function applySignal(state, from, type, payload) {
        const pc = state.pc;
        const key = Number(from);

        if (type === 'refresh') {
            if (!isOfferer(key) || pc.signalingState !== 'stable') {
                return;
            }

            state.makingOffer = true;

            try {
                await armVideo(pc);
                await pc.setLocalDescription();
                await sendSignal(key, 'offer', pc.localDescription);
            } finally {
                state.makingOffer = false;
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
            } catch (error) {
                console.error('[huddle] ice candidate failed', error);
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

        try {
            await pc.setRemoteDescription(description(payload));
        } catch (error) {
            if (!(state.polite && type === 'offer' && pc.signalingState === 'have-local-offer')) {
                console.error('[huddle] signal failed', type, error);

                return;
            }

            await pc.setLocalDescription({ type: 'rollback' });
            await pc.setRemoteDescription(description(payload));
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

        const body = (type === 'offer' || type === 'answer')
            ? { to: Number(to), type, payload: description(payload) }
            : { to: Number(to), type, payload };

        let lastError = null;

        for (let attempt = 0; attempt < 3; attempt += 1) {
            try {
                await api.post(`/workspaces/${workspaceSlug}/calls/${call.value.id}/signal`, body);

                return;
            } catch (error) {
                lastError = error;
                const status = error?.response?.status;

                if (status === 403 || status === 404 || status === 410 || status === 422) {
                    break;
                }

                await new Promise((resolve) => setTimeout(resolve, 250 * (attempt + 1)));
            }
        }

        console.error('[huddle] signal was not delivered', type, lastError?.response?.status || lastError?.message);
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
            updateConnection();
        } catch {
            /* keep the last known roster */
        } finally {
            syncBusy = false;
        }
    }

    function prunePeers() {
        const seated = new Set(others.value.map((person) => Number(person.user_id)));

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
                enqueue(state, async () => {
                    if (state.pc.signalingState !== 'stable') {
                        return;
                    }

                    state.makingOffer = true;

                    try {
                        await armVideo(state.pc);
                        await state.pc.setLocalDescription();
                        await sendSignal(peerId, 'offer', state.pc.localDescription);
                    } finally {
                        state.makingOffer = false;
                    }
                });

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

            screenTrack = track;
            sharing.value = true;
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

    function stopSharing() {
        const track = screenTrack;
        screenTrack = null;
        sharing.value = false;

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
