/**
 * On-device AI for SmackChat — capability waterfall.
 *
 * Tier 1: Chrome built-in Gemini Nano (Summarizer / Rewriter / Writer / LanguageModel).
 * Tier 2: Transformers.js Web Worker (`resources/js/workers/ai.worker.js`) on every
 *         device that cannot run Gemini Nano — iOS Safari, Chrome on iOS, Android,
 *         Firefox, macOS Safari, older or enterprise-blocked Chrome.
 *
 * All inference runs on the device. Message text is end-to-end encrypted and is never
 * posted to the SmackChat API; the only network traffic here is the one-time model
 * download, cached in `CacheStorage` for the next session.
 */

import { reactive } from 'vue';

const NANO_FAILED_KEY = 'smack.ai.nanoFailed';
const LOCAL_ENABLED_KEY = 'smack.ai.localFallbackEnabled';
const LOCAL_READY_KEY = 'smack.ai.localFallbackReady';
const LOCAL_TIER_KEY = 'smack.ai.localTier';
const LOCAL_HINT_KEY = 'smack.ai.localHint';
const LOCAL_MODEL_KEY = 'smack.ai.localModel';
const LOCAL_ATTEMPT_KEY = 'smack.ai.localAttempt';
const LOCAL_BLOCKED_KEY = 'smack.ai.localBlocked';
const LOCAL_CACHE_NAME = 'transformers-cache';
const LOCAL_HASH_CACHE_NAME = 'experimental_transformers-hash-cache';
const THREAD_SUMMARY_CACHE_PREFIX = 'smack.ai.threadSummary.v1.';

const MAX_SUMMARY_CHARS = 15000;
const TOKEN_PREFIX = '__SMACK_TOKEN_';
const TOKEN_SUFFIX = '__';

const NANO_SURFACES = ['Summarizer', 'Rewriter', 'Writer', 'LanguageModel'];

const UNAVAILABLE = 'unavailable';
const AVAILABLE = 'available';
const DOWNLOADABLE = 'downloadable';
const DOWNLOADING = 'downloading';

const GEMINI_HINT = 'Built-in Browser Model';

/**
 * Local models, largest first. Each attempt runs in a fresh worker.
 *
 * `bytes` is the real size of the ONNX weights for the dtype each device loads
 * (WebGPU: q4f16, WASM: q8). Transformers.js holds the whole file in memory, writes a
 * copy into CacheStorage, and ONNX copies it again into the WASM heap, so peak memory
 * is roughly 2–3× this number. That is what killed phone tabs: iOS Safari and most
 * Android phones cannot spare ~1 GB for one tab.
 */
const LOCAL_MODELS = {
    large: {
        id: 'onnx-community/Qwen2.5-1.5B-Instruct',
        label: 'Qwen2.5 1.5B',
        bytes: { webgpu: 1222e6 },
    },
    small: {
        id: 'onnx-community/Qwen2.5-0.5B-Instruct',
        label: 'Qwen2.5 0.5B',
        bytes: { webgpu: 483e6, wasm: 512e6 },
    },
    tiny: {
        id: 'onnx-community/SmolLM2-360M-Instruct-ONNX',
        label: 'SmolLM2 360M',
        bytes: { wasm: 363e6 },
    },
    micro: {
        id: 'onnx-community/SmolLM2-135M-Instruct-ONNX',
        label: 'SmolLM2 135M',
        bytes: { wasm: 136e6 },
    },
};

const DTYPE_FOR_DEVICE = { webgpu: 'q4f16', wasm: 'q8' };

function formatBytes(bytes) {
    return bytes >= 1e9 ? `~${(bytes / 1e9).toFixed(1)} GB` : `~${Math.round(bytes / 10e6) * 10} MB`;
}

function makeStep(model, device) {
    const bytes = model.bytes[device] || 0;

    return {
        model: { id: model.id, label: model.label, size: formatBytes(bytes) },
        device,
        dtype: DTYPE_FOR_DEVICE[device],
        bytes,
    };
}

function stepKey(step) {
    return step ? `${step.model.id}|${step.device}|${step.dtype}` : '';
}

/** Reactive status shared by every screen that offers on-device AI. */
export const ai = reactive({
    engine: UNAVAILABLE,
    modelState: 'not-downloaded',
    downloadPercent: 0,
    downloadLabel: '',
    modelSizeHint: '',
    isGenerating: false,
    isMobile: false,
    isIos: false,
    hasLocalCache: false,
});

function storageGet(key) {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function storageSet(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch {
        /* storage can be blocked or full */
    }
}

function storageRemove(key) {
    try {
        localStorage.removeItem(key);
    } catch {
        /* ignore */
    }
}

function clampPercent(value) {
    const percent = Math.round(Number(value) || 0);

    return Math.max(0, Math.min(100, percent));
}

/* ------------------------------------------------------------------ */
/* Capability detection                                                */
/* ------------------------------------------------------------------ */

function apiSurface(name) {
    if (typeof self === 'undefined') {
        return null;
    }

    if (
        typeof self[name] === 'function'
        || (self[name] && (typeof self[name].create === 'function' || typeof self[name].availability === 'function'))
    ) {
        return self[name];
    }

    const map = {
        Summarizer: self.ai?.summarizer,
        Rewriter: self.ai?.rewriter,
        Writer: self.ai?.writer,
        LanguageModel: self.ai?.languageModel || self.ai?.assistant,
    };

    return map[name] || null;
}

function normalizeAvailability(value) {
    if (value == null) {
        return UNAVAILABLE;
    }

    if (typeof value === 'string') {
        const key = value.toLowerCase();
        if (key === 'readily' || key === AVAILABLE) return AVAILABLE;
        if (key === 'after-download' || key === DOWNLOADABLE) return DOWNLOADABLE;
        if (key === DOWNLOADING) return DOWNLOADING;
        if (key === 'no' || key === UNAVAILABLE) return UNAVAILABLE;
        return value;
    }

    if (typeof value === 'object') {
        if (value.available) {
            return normalizeAvailability(value.available);
        }
        if (value.default) {
            return normalizeAvailability(value.default);
        }
    }

    return UNAVAILABLE;
}

function isUsable(status) {
    return status === AVAILABLE || status === DOWNLOADABLE || status === DOWNLOADING;
}

async function probeAvailability(surface) {
    if (!surface) {
        return UNAVAILABLE;
    }

    try {
        if (typeof surface.availability === 'function') {
            return normalizeAvailability(await surface.availability());
        }

        if (typeof surface.capabilities === 'function') {
            return normalizeAvailability(await surface.capabilities());
        }
    } catch {
        return UNAVAILABLE;
    }

    if (typeof surface.create === 'function') {
        return DOWNLOADABLE;
    }

    return UNAVAILABLE;
}

function detectDevice() {
    if (typeof navigator === 'undefined') {
        return;
    }

    const ua = navigator.userAgent || '';
    // iPadOS reports a desktop Mac UA; touch points tell it apart from a real Mac.
    ai.isIos = /iPad|iPhone|iPod/i.test(ua)
        || (/Macintosh/i.test(ua) && navigator.maxTouchPoints > 1);
    ai.isMobile = ai.isIos || /Android|Mobile|IEMobile|Opera Mini/i.test(ua);
}

function isSafariBrowser() {
    if (typeof navigator === 'undefined') {
        return false;
    }

    const ua = navigator.userAgent || '';

    return /safari/i.test(ua) && !/chrome|chromium|crios|fxios|edg|firefox|android/i.test(ua);
}

function hintFor(step) {
    const where = ai.isIos ? 'iOS' : ai.isMobile ? 'Android' : 'Desktop';

    return `${step.model.size} (${where} Local)`;
}

function isPrimaryModel(model) {
    return model?.id === fullLadder()[0]?.model.id;
}

function deviceMemoryGb() {
    const value = Number(typeof navigator !== 'undefined' ? navigator.deviceMemory : 0);

    return Number.isFinite(value) && value > 0 ? value : 0;
}

/**
 * Every model attempt for this browser, fastest viable first, before crash blocks.
 *
 * Phones run WASM only. Mobile WebGPU drivers crash the GPU process or return garbage
 * with f16 weights, and a crash there takes the whole tab down with no chance to fall
 * back. iPhone gets the 135M model: Safari kills a tab long before the 360M weights
 * (×3 while loading) fit. Android gets the 360M model only when the browser reports
 * 6 GB or more of RAM.
 */
function fullLadder() {
    const gpu = typeof navigator !== 'undefined' && !!navigator.gpu;
    const lite = storageGet(LOCAL_TIER_KEY) === 'lite';

    if (ai.isIos) {
        return [makeStep(LOCAL_MODELS.micro, 'wasm')];
    }

    if (ai.isMobile) {
        return deviceMemoryGb() >= 6
            ? [makeStep(LOCAL_MODELS.tiny, 'wasm'), makeStep(LOCAL_MODELS.micro, 'wasm')]
            : [makeStep(LOCAL_MODELS.micro, 'wasm')];
    }

    if (!gpu || isSafariBrowser()) {
        return [makeStep(LOCAL_MODELS.tiny, 'wasm'), makeStep(LOCAL_MODELS.micro, 'wasm')];
    }

    return [
        ...(lite ? [] : [makeStep(LOCAL_MODELS.large, 'webgpu')]),
        makeStep(LOCAL_MODELS.small, 'webgpu'),
        makeStep(LOCAL_MODELS.tiny, 'wasm'),
        makeStep(LOCAL_MODELS.micro, 'wasm'),
    ];
}

/** The ladder minus any model that crashed this page before. */
function localLadder() {
    const blocked = blockedSteps();

    return fullLadder().filter((step) => !blocked.includes(stepKey(step)));
}

/** The stored model, only when it is still a valid choice for this device. */
function storedStep() {
    try {
        const stored = JSON.parse(storageGet(LOCAL_MODEL_KEY) || 'null');

        // Entries from before dtypes were stored: WebGPU already used q4f16, so those
        // installs stay valid. Old WASM installs used q4, which is no longer loaded.
        const dtype = stored?.dtype || (stored?.device === 'webgpu' ? 'q4f16' : '');

        if (!stored?.id || !stored?.device || !dtype) {
            return null;
        }

        return localLadder().find((step) => stepKey(step) === `${stored.id}|${stored.device}|${dtype}`) || null;
    } catch {
        return null;
    }
}

function selectedStep() {
    return storedStep() || localLadder()[0];
}

/* ------------------------------------------------------------------ */
/* Crash guard                                                         */
/* ------------------------------------------------------------------ */

/*
 * When a phone runs out of memory it kills the tab, not the worker, so no error ever
 * reaches this code and the next attempt would crash the same way. Before loading or
 * generating, the step is written to localStorage; a clean finish, navigation
 * (`pagehide`), or the tab going to the background removes it. If the page boots and
 * finds it still there, that model crashed the visible tab and is skipped from now on.
 */

function blockedSteps() {
    try {
        const list = JSON.parse(storageGet(LOCAL_BLOCKED_KEY) || '[]');

        return Array.isArray(list) ? list : [];
    } catch {
        return [];
    }
}

let attemptActive = null;

function beginAttempt(step, phase) {
    attemptActive = { key: stepKey(step), phase };
    storageSet(LOCAL_ATTEMPT_KEY, JSON.stringify(attemptActive));
}

function endAttempt() {
    attemptActive = null;
    storageRemove(LOCAL_ATTEMPT_KEY);
}

function reconcileCrashedAttempt() {
    let attempt = null;

    try {
        attempt = JSON.parse(storageGet(LOCAL_ATTEMPT_KEY) || 'null');
    } catch {
        attempt = null;
    }

    storageRemove(LOCAL_ATTEMPT_KEY);

    if (!attempt?.key) {
        return;
    }

    const blocked = blockedSteps();

    if (!blocked.includes(attempt.key)) {
        blocked.push(attempt.key);
        storageSet(LOCAL_BLOCKED_KEY, JSON.stringify(blocked));
    }

    try {
        const stored = JSON.parse(storageGet(LOCAL_MODEL_KEY) || 'null');

        if (stored && `${stored.id}|${stored.device}|${stored.dtype}` === attempt.key) {
            storageRemove(LOCAL_READY_KEY);
            storageRemove(LOCAL_MODEL_KEY);
            storageRemove(LOCAL_HINT_KEY);
        }
    } catch {
        /* ignore */
    }
}

if (typeof window !== 'undefined') {
    // A navigation or reload is not a crash.
    window.addEventListener('pagehide', () => storageRemove(LOCAL_ATTEMPT_KEY));
}

if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            // A phone may kill a background tab for any reason; do not blame the model.
            storageRemove(LOCAL_ATTEMPT_KEY);
            lastWorkerSignal = Date.now();

            if (ai.isMobile && !isWorkerBusy()) {
                stopWorker();
            }

            return;
        }

        lastWorkerSignal = Date.now();

        if (attemptActive) {
            storageSet(LOCAL_ATTEMPT_KEY, JSON.stringify(attemptActive));
        }
    });
}

function localModelHint() {
    const stored = storageGet(LOCAL_HINT_KEY);

    if (stored && storedStep()) {
        return stored;
    }

    const step = selectedStep();

    return step ? hintFor(step) : '';
}

function localEngineSupported() {
    return typeof Worker !== 'undefined' && typeof WebAssembly !== 'undefined';
}

async function hasLocalModelCache() {
    if (typeof caches === 'undefined') {
        return false;
    }

    try {
        const keys = await caches.keys();

        return keys.includes(LOCAL_CACHE_NAME);
    } catch {
        return false;
    }
}

async function detectNano() {
    if (storageGet(NANO_FAILED_KEY) === 'true') {
        return { usable: false, ready: false };
    }

    const statuses = await Promise.all(NANO_SURFACES.map((name) => probeAvailability(apiSurface(name))));

    return {
        usable: statuses.some(isUsable),
        ready: statuses.includes(AVAILABLE),
    };
}

async function useLocalFallback() {
    ai.downloadPercent = 0;
    ai.downloadLabel = '';

    reconcileCrashedAttempt();

    if (!localEngineSupported() || !localLadder().length) {
        ai.engine = UNAVAILABLE;
        ai.modelState = UNAVAILABLE;
        ai.modelSizeHint = '';
        return;
    }

    // A model chosen by an older build (e.g. Qwen 0.5B on Android WebGPU) or one that
    // has since crashed this device is no longer valid: forget it and free its space.
    if (storageGet(LOCAL_MODEL_KEY) && !storedStep()) {
        try {
            const stale = JSON.parse(storageGet(LOCAL_MODEL_KEY) || 'null');
            await pruneLocalModels([stale?.id]);
        } catch {
            /* ignore */
        }

        storageRemove(LOCAL_MODEL_KEY);
        storageRemove(LOCAL_READY_KEY);
        storageRemove(LOCAL_HINT_KEY);
    }

    ai.engine = 'transformers-local';
    ai.modelSizeHint = localModelHint();

    const cached = await hasLocalModelCache();
    const flagged = storageGet(LOCAL_READY_KEY) === 'true' && !!storedStep();
    const enabled = storageGet(LOCAL_ENABLED_KEY) === 'true';

    if (flagged && !cached) {
        storageRemove(LOCAL_READY_KEY);
    }

    ai.hasLocalCache = cached;
    ai.modelState = cached && flagged && enabled ? 'ready' : 'not-downloaded';
    ai.downloadPercent = ai.modelState === 'ready' ? 100 : 0;
}

let detection = null;

/**
 * Detect the best engine for this device. Safe to call from every screen; the
 * probe only runs once per page load.
 */
export function initAiCapabilities() {
    detection ??= detectCapabilities().catch((error) => {
        detection = null;
        throw error;
    });

    return detection;
}

async function detectCapabilities() {
    detectDevice();

    const nano = await detectNano();

    if (nano.usable) {
        ai.engine = 'gemini-nano';
        ai.modelState = nano.ready ? 'ready' : 'not-downloaded';
        ai.modelSizeHint = GEMINI_HINT;
        ai.downloadPercent = nano.ready ? 100 : 0;
        ai.downloadLabel = '';

        return ai;
    }

    await useLocalFallback();

    return ai;
}

export function aiEngineLabel() {
    if (ai.engine === 'gemini-nano') {
        return 'Chrome Built-in Gemini Nano';
    }

    if (ai.engine === 'transformers-local') {
        return `On-Device Local AI (${ai.modelSizeHint})`;
    }

    return 'Unavailable in this browser';
}

export function aiReadyBadge() {
    if (ai.modelState !== 'ready') {
        return '';
    }

    if (ai.engine === 'gemini-nano') {
        return '⚡ Gemini Nano Ready';
    }

    return ai.engine === 'transformers-local' ? '🔒 Offline AI Ready' : '';
}

/* ------------------------------------------------------------------ */
/* One-time download                                                   */
/* ------------------------------------------------------------------ */

function downloadRequiredError(cause) {
    const error = new Error('The offline AI model still needs to be downloaded once on this device.');
    error.code = 'model-download-required';
    error.cause = cause;

    return error;
}

export function isDownloadRequired(error) {
    return error?.code === 'model-download-required';
}

function nanoMonitor(onDownloadProgress) {
    return (monitor) => {
        monitor.addEventListener('downloadprogress', (event) => {
            const loaded = Number(event?.loaded);
            const total = Number(event?.total);
            let ratio = Number.isFinite(loaded) ? loaded : 0;

            if (Number.isFinite(total) && total > 0) {
                ratio = loaded / total;
            } else if (ratio > 1) {
                ratio /= 100;
            }

            const percent = clampPercent(ratio * 100);
            ai.downloadPercent = percent;
            ai.downloadLabel = `Downloading Gemini Nano to browser (one-time setup): ${percent}%`;
            onDownloadProgress?.(percent);
        });
    };
}

function markNanoReady() {
    ai.engine = 'gemini-nano';
    ai.modelState = 'ready';
    ai.modelSizeHint = GEMINI_HINT;
    ai.downloadPercent = 100;
    ai.downloadLabel = '';
}

async function downloadNano() {
    ai.modelState = 'downloading';
    ai.downloadPercent = 0;
    ai.downloadLabel = 'Downloading Gemini Nano to browser (one-time setup): 0%';

    let downloadable = null;

    for (const name of NANO_SURFACES) {
        const surface = apiSurface(name);

        if (!surface) {
            continue;
        }

        const status = await probeAvailability(surface);

        if (status === AVAILABLE) {
            markNanoReady();
            return;
        }

        if (isUsable(status) && !downloadable) {
            downloadable = surface;
        }
    }

    if (!downloadable) {
        throw new Error('Chrome built-in AI is not available on this device.');
    }

    const session = await downloadable.create({ monitor: nanoMonitor() });
    destroySession(session);
    markNanoReady();
}

function setLocalLabel(percent) {
    ai.downloadLabel = `Downloading offline AI model (${ai.modelSizeHint}, one-time setup): ${percent}%`;
}

function markLocalReady(step) {
    storageSet(LOCAL_ENABLED_KEY, 'true');
    storageSet(LOCAL_READY_KEY, 'true');
    storageSet(LOCAL_MODEL_KEY, JSON.stringify({
        id: step.model.id,
        label: step.model.label,
        size: step.model.size,
        device: step.device,
        dtype: step.dtype,
    }));
    storageSet(LOCAL_HINT_KEY, hintFor(step));
    storageSet(LOCAL_TIER_KEY, isPrimaryModel(step.model) ? 'full' : 'lite');

    ai.modelSizeHint = hintFor(step);
    ai.modelState = 'ready';
    ai.downloadPercent = 100;
    ai.downloadLabel = '';
    ai.hasLocalCache = true;
}

async function failoverToLocal() {
    storageSet(NANO_FAILED_KEY, 'true');
    await useLocalFallback();
}

async function pruneLocalModels(ids) {
    const unique = [...new Set(ids)].filter(Boolean);

    if (!unique.length || typeof caches === 'undefined') {
        return;
    }

    try {
        const cache = await caches.open(LOCAL_CACHE_NAME);

        for (const request of await cache.keys()) {
            if (unique.some((id) => request.url.includes(id))) {
                await cache.delete(request);
            }
        }
    } catch {
        /* pruning is best effort */
    }
}

function loadLocalStep(step) {
    const target = ensureWorker(step);

    return new Promise((resolve, reject) => {
        pendingDownload = { resolve, reject };
        lastWorkerSignal = Date.now();
        armWatchdog();
        target.postMessage({ type: 'load' });
    });
}

async function hasCachedModel(id) {
    if (typeof caches === 'undefined') {
        return false;
    }

    try {
        const cache = await caches.open(LOCAL_CACHE_NAME);

        return (await cache.keys()).some((request) => request.url.includes(id) && request.url.includes('.onnx'));
    } catch {
        return false;
    }
}

/** Throws when the browser clearly has no room for this model's weights. */
async function ensureStorageFor(step) {
    if (!step.bytes || typeof navigator === 'undefined' || !navigator.storage?.estimate) {
        return;
    }

    if (await hasCachedModel(step.model.id)) {
        return;
    }

    try {
        const { quota = 0, usage = 0 } = await navigator.storage.estimate();

        if (quota > 0 && quota - usage < step.bytes * 1.1) {
            const error = new Error(
                `Not enough browser storage for the offline model (${step.model.size} needed, ${formatBytes(Math.max(0, quota - usage))} free). Free some space on this device and try again.`,
            );
            error.code = 'storage';
            throw error;
        }
    } catch (error) {
        if (error?.code === 'storage') {
            throw error;
        }
        /* estimate is best effort */
    }
}

async function downloadLocal() {
    try {
        await navigator.storage?.persist?.();
    } catch {
        /* best effort: the download still works without persistence */
    }

    storageSet(LOCAL_ENABLED_KEY, 'true');

    const failed = [];
    let lastError = null;

    for (const step of localLadder()) {
        ai.modelSizeHint = hintFor(step);
        ai.modelState = 'downloading';
        ai.downloadPercent = 0;
        setLocalLabel(0);

        try {
            await ensureStorageFor(step);
            beginAttempt(step, 'load');
            await loadLocalStep(step);
            endAttempt();
            markLocalReady(step);
            await pruneLocalModels(failed.filter((id) => id !== step.model.id));

            return;
        } catch (error) {
            endAttempt();
            lastError = error;

            if (error?.code !== 'storage') {
                failed.push(step.model.id);
            }

            // Terminate the worker: the next candidate starts with a clean WASM heap and
            // the failed runtime stops holding onto memory.
            stopWorker();
            ai.downloadPercent = 0;

            await new Promise((resolve) => setTimeout(resolve, 60));
        }
    }

    ai.modelState = 'not-downloaded';
    ai.downloadPercent = 0;
    ai.downloadLabel = '';
    ai.modelSizeHint = localModelHint();

    const reason = String(lastError?.message || 'The model could not be loaded.');

    if (lastError?.code === 'storage') {
        throw lastError;
    }

    if (/bad_alloc|allocat|memory/i.test(reason)) {
        throw new Error(
            'This browser could not allocate enough memory for the offline model. Close other tabs and try again, or use Chrome to get the built-in Gemini Nano.',
        );
    }

    throw new Error(`No on-device model could start in this browser. ${reason}`);
}

/**
 * Run the one-time setup. Must be called from a user gesture for Gemini Nano.
 * @returns {Promise<typeof ai>}
 */
export async function prepareModel() {
    await initAiCapabilities();

    if (ai.engine === UNAVAILABLE) {
        throw new Error('On-device AI is unavailable in this browser.');
    }

    if (ai.modelState === 'ready') {
        return ai;
    }

    if (ai.engine === 'gemini-nano') {
        try {
            await downloadNano();
        } catch (error) {
            await failoverToLocal();

            if (ai.modelState === 'ready') {
                return ai;
            }

            throw downloadRequiredError(error);
        }

        return ai;
    }

    await downloadLocal();

    return ai;
}

export async function removeDownloadedLocalModel() {
    stopWorker();

    if (typeof caches !== 'undefined') {
        try {
            await caches.delete(LOCAL_CACHE_NAME);
        } catch {
            /* cache may be blocked */
        }

        try {
            await caches.delete(LOCAL_HASH_CACHE_NAME);
        } catch {
            /* ignore */
        }
    }

    storageRemove(LOCAL_ENABLED_KEY);
    storageRemove(LOCAL_READY_KEY);
    storageRemove(LOCAL_TIER_KEY);
    storageRemove(LOCAL_HINT_KEY);
    storageRemove(LOCAL_MODEL_KEY);
    // Deleting the model is the explicit "start over": give crashed models another try.
    storageRemove(LOCAL_BLOCKED_KEY);
    storageRemove(LOCAL_ATTEMPT_KEY);

    ai.downloadPercent = 0;
    ai.downloadLabel = '';
    ai.hasLocalCache = false;

    if (ai.engine === 'transformers-local') {
        ai.modelState = 'not-downloaded';
        ai.modelSizeHint = localModelHint();
    }
}

/**
 * Delete the cached model and immediately download the best model for this browser.
 * Used by Settings so an existing install can switch to a faster/smaller model.
 */
export async function redownloadLocalModel() {
    await removeDownloadedLocalModel();
    await prepareModel();

    return ai;
}

/* ------------------------------------------------------------------ */
/* Transformers.js worker plumbing                                     */
/* ------------------------------------------------------------------ */

let worker = null;
let workerStep = null;
let requestSeq = 0;
let pendingDownload = null;
let progressSink = null;
const requests = new Map();

/*
 * Phones suspend or kill a worker without telling the page (background tab, memory
 * pressure). Without a watchdog the promise never settles and the composer stays
 * busy, so Send is disabled until a reload. Silence is measured only while visible.
 */
const LOAD_SILENCE_MS = 150000;
const GENERATE_SILENCE_MS = 90000;
const MOBILE_IDLE_STOP_MS = 120000;

let lastWorkerSignal = Date.now();
let watchdogTimer = null;
let idleStopTimer = null;

function isWorkerBusy() {
    return !!pendingDownload || requests.size > 0;
}

function armWatchdog() {
    clearTimeout(idleStopTimer);
    idleStopTimer = null;

    if (watchdogTimer) {
        return;
    }

    watchdogTimer = setInterval(() => {
        if (!isWorkerBusy()) {
            clearInterval(watchdogTimer);
            watchdogTimer = null;
            scheduleIdleStop();
            return;
        }

        if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
            return;
        }

        const limit = pendingDownload ? LOAD_SILENCE_MS : GENERATE_SILENCE_MS;

        if (Date.now() - lastWorkerSignal > limit) {
            failWorker(new Error('The offline AI model stopped responding on this device. Try again.'), false);
        }
    }, 5000);
}

/** On a phone, free the model's memory when it has not been used for a while. */
function scheduleIdleStop() {
    clearTimeout(idleStopTimer);
    idleStopTimer = null;

    if (!ai.isMobile || !worker) {
        return;
    }

    idleStopTimer = setTimeout(() => {
        idleStopTimer = null;

        if (!isWorkerBusy()) {
            stopWorker();
        }
    }, MOBILE_IDLE_STOP_MS);
}

function ensureWorker(step) {
    if (worker && !step) {
        return worker;
    }

    if (worker) {
        worker.terminate();
        worker = null;
    }

    workerStep = step || selectedStep();

    if (!workerStep) {
        throw downloadRequiredError();
    }

    worker = new Worker(new URL('./workers/ai.worker.js', import.meta.url), { type: 'module' });
    worker.addEventListener('message', onWorkerMessage);
    worker.addEventListener('error', onWorkerError);
    worker.postMessage({
        type: 'init',
        modelId: workerStep.model.id,
        modelLabel: workerStep.model.label,
        device: workerStep.device,
        dtype: workerStep.dtype,
        isIos: ai.isIos,
        isMobile: ai.isMobile,
    });

    return worker;
}

function stopWorker() {
    clearTimeout(idleStopTimer);
    idleStopTimer = null;

    if (!worker) {
        return;
    }

    const target = worker;
    worker = null;
    workerStep = null;
    target.terminate();

    const stopped = new Error('On-device AI stopped.');

    requests.forEach((request) => request.reject(stopped));
    requests.clear();
    pendingDownload?.reject?.(stopped);
    pendingDownload = null;
    progressSink = null;
}

function settleRequest(requestId, settle) {
    const request = requests.get(requestId);

    if (!request) {
        return;
    }

    requests.delete(requestId);
    progressSink = null;
    settle(request);
}

function onWorkerMessage(event) {
    const data = event.data || {};
    lastWorkerSignal = Date.now();

    if (data.type === 'download-progress') {
        const percent = clampPercent(data.percent);
        ai.downloadPercent = percent;

        if (ai.modelState === 'downloading') {
            setLocalLabel(percent);
        }

        progressSink?.(percent);
        return;
    }

    if (data.type === 'download-complete') {
        if (workerStep) {
            markLocalReady(workerStep);
        }

        progressSink?.(100);

        const pending = pendingDownload;
        pendingDownload = null;
        pending?.resolve?.();
        return;
    }

    if (data.type === 'chunk') {
        const request = requests.get(data.requestId);

        if (request) {
            request.text += String(data.text || '');
            request.onChunk?.(request.text);
        }
        return;
    }

    if (data.type === 'done') {
        settleRequest(data.requestId, (request) => {
            request.resolve(String(data.text || request.text || '').trim());
        });
        return;
    }

    if (data.type === 'error') {
        settleRequest(data.requestId, (request) => {
            request.reject(new Error(data.message || 'On-device model failed.'));
        });
        return;
    }

    if (data.type === 'load-error') {
        const error = new Error(data.message || 'The offline AI model could not be loaded.');

        pendingDownload?.reject?.(error);
        pendingDownload = null;
        requests.forEach((request) => request.reject(error));
        requests.clear();
        progressSink = null;

        invalidateReadyModel();
    }
}

/**
 * A model that was marked ready could not be loaded in this session (cache evicted,
 * driver reset, …). Drop the ready flag so the next AI action runs setup again.
 */
function invalidateReadyModel() {
    if (ai.modelState !== 'ready') {
        return;
    }

    storageRemove(LOCAL_READY_KEY);
    ai.modelState = 'not-downloaded';
    ai.downloadPercent = 0;
    ai.downloadLabel = '';
}

function onWorkerError(event) {
    failWorker(new Error(event?.message || 'The offline AI worker stopped unexpectedly.'), true);
}

/**
 * Tear down a worker that crashed or went silent, and reject everything waiting on it.
 * `invalidate` drops the ready flag; a silent (suspended) worker keeps it, because the
 * model files are still cached and a fresh worker can load them.
 */
function failWorker(error, invalidate) {
    clearInterval(watchdogTimer);
    watchdogTimer = null;
    endAttempt();

    const target = worker;
    worker = null;
    workerStep = null;

    try {
        target?.terminate();
    } catch {
        /* already gone */
    }

    pendingDownload?.reject?.(error);
    pendingDownload = null;
    requests.forEach((request) => request.reject(error));
    requests.clear();
    progressSink = null;

    if (invalidate) {
        invalidateReadyModel();
    }
}

function localInputCap() {
    if (ai.isIos) {
        return 1800;
    }

    if (ai.isMobile) {
        return 2500;
    }

    // Prompt processing is the slow half of a summary on CPU, so only the big
    // WebGPU model gets the long window.
    return workerStep?.model?.id === LOCAL_MODELS.large.id ? 6000 : 3000;
}

function localMaxTokens(kind = 'write') {
    const ceiling = ai.isIos ? 150 : 200;
    const wanted = kind === 'write' ? 180 : kind === 'summarize' ? 160 : 140;

    return Math.min(wanted, ceiling);
}

function capText(text, cap, fromEnd = false) {
    const source = String(text || '');

    if (source.length <= cap) {
        return source;
    }

    return fromEnd ? source.slice(source.length - cap) : source.slice(0, cap);
}

function runLocal({ system, user, fromEnd = false, maxTokens }, { onChunk, onDownloadProgress } = {}) {
    if (ai.modelState !== 'ready') {
        return Promise.reject(downloadRequiredError());
    }

    let target;

    try {
        target = ensureWorker();
    } catch (error) {
        return Promise.reject(error);
    }

    const requestId = ++requestSeq;
    const cap = localInputCap();

    if (workerStep) {
        beginAttempt(workerStep, 'generate');
    }

    return new Promise((resolve, reject) => {
        progressSink = onDownloadProgress || null;

        requests.set(requestId, {
            text: '',
            onChunk,
            resolve: (text) => {
                endAttempt();
                resolve(text);
            },
            reject: (error) => {
                endAttempt();
                reject(error);
            },
        });

        lastWorkerSignal = Date.now();
        armWatchdog();

        target.postMessage({
            type: 'generate',
            requestId,
            messages: [
                { role: 'system', content: system },
                { role: 'user', content: capText(user, cap, fromEnd) },
            ],
            maxNewTokens: maxTokens || localMaxTokens(),
            maxChars: cap,
        });
    });
}

/* ------------------------------------------------------------------ */
/* Gemini Nano sessions                                                */
/* ------------------------------------------------------------------ */

function destroySession(session) {
    try {
        session?.destroy?.();
    } catch {
        /* already released */
    }
}

async function readStream(stream, onChunk) {
    let previous = '';
    let latest = '';

    if (!stream) {
        return '';
    }

    if (typeof stream[Symbol.asyncIterator] === 'function') {
        for await (const chunk of stream) {
            const piece = typeof chunk === 'string' ? chunk : String(chunk?.content ?? chunk ?? '');
            latest = piece.startsWith(previous) ? piece : `${previous}${piece}`;
            previous = latest;
            onChunk?.(latest);
        }

        return latest;
    }

    if (typeof stream.getReader === 'function') {
        const reader = stream.getReader();
        const decoder = new TextDecoder();

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            const piece = typeof value === 'string' ? value : decoder.decode(value, { stream: true });
            latest = piece.startsWith(previous) ? piece : `${previous}${piece}`;
            previous = latest;
            onChunk?.(latest);
        }

        return latest;
    }

    const text = String(stream);
    onChunk?.(text);

    return text;
}

async function streamSession(session, method, input, onChunk) {
    const streaming = session?.[`${method}Streaming`];

    if (typeof streaming === 'function') {
        return readStream(streaming.call(session, input), onChunk);
    }

    const call = session?.[method];

    if (typeof call !== 'function') {
        return '';
    }

    const result = String((await call.call(session, input)) || '');
    onChunk?.(result);

    return result;
}

async function runPromptSession(systemPrompt, user, hooks) {
    const promptApi = apiSurface('LanguageModel');

    if (!promptApi) {
        throw new Error('On-device drafting needs Chrome built-in AI or the offline model.');
    }

    let session = null;

    try {
        session = await promptApi.create({
            systemPrompt,
            monitor: nanoMonitor(hooks.onDownloadProgress),
        });

        return await streamSession(session, 'prompt', user, hooks.onChunk);
    } finally {
        destroySession(session);
    }
}

async function runNano(kind, instruction, options, hooks) {
    if (kind === 'rewrite') {
        const rewriter = apiSurface('Rewriter');

        if (rewriter && !options.fixGrammar) {
            let session = null;

            try {
                session = await rewriter.create({
                    tone: options.tone,
                    length: options.length,
                    format: 'plain-text',
                    sharedContext: 'Workplace chat message in SmackChat.',
                    monitor: nanoMonitor(hooks.onDownloadProgress),
                });

                return await streamSession(session, 'rewrite', instruction.user, hooks.onChunk);
            } catch (error) {
                if (!apiSurface('LanguageModel')) {
                    throw error;
                }
            } finally {
                destroySession(session);
            }
        }

        return runPromptSession(instruction.system, instruction.user, hooks);
    }

    if (kind === 'write') {
        const writer = apiSurface('Writer');

        if (writer) {
            let session = null;

            try {
                session = await writer.create({
                    tone: options.tone || 'neutral',
                    length: options.length || 'short',
                    format: 'plain-text',
                    sharedContext: 'Workplace chat message in SmackChat.',
                    monitor: nanoMonitor(hooks.onDownloadProgress),
                });

                return await streamSession(session, 'write', instruction.user, hooks.onChunk);
            } catch (error) {
                if (!apiSurface('LanguageModel')) {
                    throw error;
                }
            } finally {
                destroySession(session);
            }
        }

        return runPromptSession(instruction.system, instruction.user, hooks);
    }

    const summarizer = apiSurface('Summarizer');

    if (summarizer) {
        let session = null;

        try {
            session = await summarizer.create({
                type: options.type || 'key-points',
                format: 'markdown',
                length: options.length || 'short',
                sharedContext: 'Internal company chat transcript.',
                monitor: nanoMonitor(hooks.onDownloadProgress),
            });

            return await streamSession(session, 'summarize', instruction.user, hooks.onChunk);
        } catch (error) {
            if (!apiSurface('LanguageModel')) {
                throw error;
            }
        } finally {
            destroySession(session);
        }
    }

    return runPromptSession(instruction.system, instruction.user, hooks);
}

/* ------------------------------------------------------------------ */
/* Prompt shaping                                                      */
/* ------------------------------------------------------------------ */

function toneHint(tone) {
    if (tone === 'more-formal' || tone === 'formal' || tone === 'professional') {
        return 'Use a polished, professional workplace tone.';
    }

    if (tone === 'more-casual' || tone === 'casual') {
        return 'Use a friendly, casual workplace tone.';
    }

    return 'Keep the existing tone.';
}

function lengthHint(length) {
    if (length === 'shorter' || length === 'short') {
        return 'Make it shorter and tighter.';
    }

    if (length === 'longer' || length === 'long') {
        return 'Add a little more clarity without padding.';
    }

    return 'Keep roughly the same length.';
}

function rewriteInstruction(text, { tone = 'as-is', length = 'as-is', action = 'rewrite' } = {}) {
    const fixGrammar = action === 'fix-grammar';

    const system = fixGrammar
        ? [
            'You fix grammar and spelling in workplace chat messages.',
            'Keep the meaning and tone. Never invent facts.',
            'Keep @mentions, URLs, and placeholders like __SMACK_TOKEN_0__ exactly as written.',
            'Return ONLY the corrected message.',
        ].join(' ')
        : [
            'You rewrite workplace chat messages for SmackChat.',
            toneHint(tone),
            lengthHint(length),
            'Keep the meaning. Never invent facts.',
            'Keep @mentions, URLs, and placeholders like __SMACK_TOKEN_0__ exactly as written.',
            'Return ONLY the rewritten message — no preamble, no quotes around the whole reply.',
        ].join(' ');

    return {
        system,
        user: text,
        fromEnd: false,
        maxTokens: localMaxTokens('rewrite'),
    };
}

function writeInstruction(brief, { tone = 'neutral', length = 'short' } = {}) {
    const system = [
        'You draft workplace chat messages for SmackChat from a short instruction.',
        toneHint(tone),
        lengthHint(length),
        'Do not invent facts beyond the instruction.',
        'Preserve @mentions, URLs, and placeholders like __SMACK_TOKEN_0__ exactly as written.',
        'Return ONLY the message text, ready to send — no preamble, no subject line, no surrounding quotes.',
    ].join(' ');

    return {
        system,
        user: brief,
        fromEnd: false,
        maxTokens: localMaxTokens('write'),
    };
}

function summaryInstruction(transcript) {
    const system = [
        'You summarize an internal company chat transcript for a teammate catching up.',
        'Return 3 to 5 short bullet points, one line each, starting with "- ".',
        'Use only information that appears in the transcript.',
        'Return ONLY the bullet points.',
    ].join(' ');

    return {
        system,
        user: transcript,
        fromEnd: true,
        maxTokens: localMaxTokens('summarize'),
    };
}

function cleanOutput(text) {
    let value = String(text || '').trim();

    value = value.replace(/^```[a-z]*\s*/i, '').replace(/```$/, '').trim();

    if (value.length > 1) {
        const first = value[0];
        const last = value[value.length - 1];

        if ((first === '"' && last === '"') || (first === '“' && last === '”') || (first === '`' && last === '`')) {
            value = value.slice(1, -1).trim();
        }
    }

    return value;
}

/**
 * The small phone models often repeat lines of the system prompt after the answer
 * ("Keep the meaning. Never invent facts."), sometimes paraphrased. Remove every
 * instruction sentence that shows up verbatim, then any trailing sentences that read
 * like an instruction to the model.
 */
function stripEchoedInstructions(text, system) {
    let value = String(text || '');
    const sentences = String(system || '')
        .split(/(?<=[.!?])\s+/)
        .map((sentence) => sentence.trim())
        .filter((sentence) => sentence.length > 12);

    for (const sentence of sentences) {
        value = value.split(sentence).join('');
    }

    const instructionTail = /(?:^|(?<=[.!?\n]))\s*(?:Keep (?:about|roughly|the (?:same|meaning|existing|tone)|@mentions|it)|Never invent|Return (?:only|ONLY)|Preserve @mentions|Do not invent|Make it (?:shorter|longer)|Use (?:a [^.!?\n]*tone|only information)|Add a little more clarity)\b[^.!?\n]*[.!?]?\s*$/;

    for (let guard = 0; guard < 8 && instructionTail.test(value); guard += 1) {
        const next = value.replace(instructionTail, '');

        if (!next.trim()) {
            break;
        }

        value = next;
    }

    return value.replace(/[ \t]{2,}/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
}

async function executeTask({ kind, options, instruction, tokens, hooks }) {
    if (ai.engine === UNAVAILABLE) {
        throw new Error('On-device AI is unavailable in this browser.');
    }

    ai.isGenerating = true;

    try {
        const raw = ai.engine === 'gemini-nano'
            ? await runNano(kind, instruction, options || {}, hooks || {})
            : stripEchoedInstructions(await runLocal(instruction, hooks || {}), instruction.system);

        return restoreTokens(cleanOutput(raw), tokens || []);
    } finally {
        ai.isGenerating = false;
    }
}

/* ------------------------------------------------------------------ */
/* Token protection                                                    */
/* ------------------------------------------------------------------ */

function placeholder(index) {
    return `${TOKEN_PREFIX}${index}${TOKEN_SUFFIX}`;
}

/**
 * Protect @mentions, URLs, and code so the local model cannot corrupt them.
 * @param {string} text
 * @returns {{ text: string, tokens: string[] }}
 */
export function protectTokens(text) {
    const source = String(text || '');
    const tokens = [];

    const stash = (match) => {
        const index = tokens.length;
        tokens.push(match);
        return placeholder(index);
    };

    let next = source.replace(/```[\s\S]*?```/g, stash);
    next = next.replace(/`[^`\n]+`/g, stash);
    next = next.replace(/\bhttps?:\/\/[^\s<>()"]+/gi, stash);
    next = next.replace(/\bwww\.[^\s()"]+/gi, stash);
    next = next.replace(
        /(^|[\s([{])@[\p{L}\p{N}][\p{L}\p{N}.\-]*(?:\s+[\p{L}\p{N}][\p{L}\p{N}.\-]*){0,4}/gu,
        (full, lead) => {
            const mention = full.slice(lead.length);
            return `${lead}${stash(mention)}`;
        },
    );

    return { text: next, tokens };
}

/**
 * @param {string} text
 * @param {string[]} tokens
 */
export function restoreTokens(text, tokens) {
    let next = String(text || '');

    for (let index = 0; index < tokens.length; index += 1) {
        const needle = placeholder(index);
        next = next.split(needle).join(tokens[index]);
    }

    return next;
}

/* ------------------------------------------------------------------ */
/* Public tasks                                                        */
/* ------------------------------------------------------------------ */

/**
 * Rewrite a workplace chat draft on the device.
 */
export async function rewriteDraftLocally(
    text,
    { tone = 'as-is', length = 'as-is', action = 'rewrite' } = {},
    { onChunk, onDownloadProgress } = {},
) {
    const source = String(text || '').trim();

    if (!source) {
        return '';
    }

    await initAiCapabilities();

    const protectedDraft = protectTokens(source);
    const fixGrammar = action === 'fix-grammar';

    return executeTask({
        kind: 'rewrite',
        options: {
            tone: fixGrammar ? 'as-is' : tone,
            length: fixGrammar ? 'as-is' : length,
            fixGrammar,
        },
        instruction: rewriteInstruction(protectedDraft.text, { tone, length, action }),
        tokens: protectedDraft.tokens,
        hooks: { onChunk, onDownloadProgress },
    });
}

/**
 * Draft a message from a short instruction on the device.
 */
export async function writeDraftLocally(
    context,
    { tone = 'neutral', length = 'short' } = {},
    { onChunk, onDownloadProgress } = {},
) {
    const source = String(context || '').trim();

    if (!source) {
        return '';
    }

    await initAiCapabilities();

    const protectedBrief = protectTokens(source);

    return executeTask({
        kind: 'write',
        options: { tone, length },
        instruction: writeInstruction(protectedBrief.text, { tone, length }),
        tokens: protectedBrief.tokens,
        hooks: { onChunk, onDownloadProgress },
    });
}

function messagePlain(message) {
    if (!message || message.deleted) {
        return '';
    }

    if (message.text && String(message.text).trim()) {
        return String(message.text).trim();
    }

    if (message.excerpt && String(message.excerpt).trim()) {
        return String(message.excerpt).trim();
    }

    return '';
}

function formatTranscript(messages) {
    const lines = [];

    for (const message of messages || []) {
        const body = messagePlain(message);

        if (!body) {
            continue;
        }

        const author = message.user?.name || (message.system_type ? 'System' : 'Someone');
        lines.push(`[${author}]: ${body}`);
    }

    let transcript = lines.join('\n');

    if (transcript.length > MAX_SUMMARY_CHARS) {
        transcript = transcript.slice(transcript.length - MAX_SUMMARY_CHARS);
        const cut = transcript.indexOf('\n');

        if (cut > 0 && cut < 200) {
            transcript = transcript.slice(cut + 1);
        }
    }

    return transcript;
}

/**
 * Summarize already-decrypted messages on the device. Nothing is uploaded.
 */
export async function summarizeMessagesLocally(
    messages,
    { type = 'key-points', length = 'short' } = {},
    { onChunk, onDownloadProgress } = {},
) {
    await initAiCapabilities();

    const transcript = formatTranscript(messages);

    if (!transcript) {
        return '';
    }

    return executeTask({
        kind: 'summarize',
        options: { type, length },
        instruction: summaryInstruction(transcript),
        tokens: [],
        hooks: { onChunk, onDownloadProgress },
    });
}

/* ------------------------------------------------------------------ */
/* Thread summary cache (localStorage, per device)                     */
/* ------------------------------------------------------------------ */

/**
 * Stable fingerprint for a thread transcript. Changes when replies are added,
 * edited, or deleted — used to invalidate the browser cache.
 * @param {Array<{ id?: string|number, deleted?: boolean, edited_at?: string, text?: string, document?: object, user?: { name?: string } }>} messages
 */
export function threadSummaryFingerprint(messages) {
    return (messages || [])
        .map((message) => {
            const body = messagePlain(message);

            return [
                message?.id ?? '',
                message?.deleted ? '1' : '0',
                message?.edited_at || '',
                message?.user?.name || '',
                body,
            ].join('\u001f');
        })
        .join('\n');
}

function threadSummaryStorageKey(workspaceSlug, threadRef) {
    return `${THREAD_SUMMARY_CACHE_PREFIX}${workspaceSlug}.${threadRef}`;
}

/**
 * @returns {{ text: string, fingerprint: string, savedAt: number } | null}
 */
export function readCachedThreadSummary(workspaceSlug, threadRef) {
    if (!workspaceSlug || !threadRef) {
        return null;
    }

    try {
        const raw = localStorage.getItem(threadSummaryStorageKey(workspaceSlug, threadRef));

        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw);

        if (!parsed?.text || !parsed?.fingerprint) {
            return null;
        }

        return {
            text: String(parsed.text),
            fingerprint: String(parsed.fingerprint),
            savedAt: Number(parsed.savedAt) || 0,
        };
    } catch {
        return null;
    }
}

export function writeCachedThreadSummary(workspaceSlug, threadRef, text, fingerprint) {
    if (!workspaceSlug || !threadRef || !text || !fingerprint) {
        return;
    }

    try {
        localStorage.setItem(
            threadSummaryStorageKey(workspaceSlug, threadRef),
            JSON.stringify({
                text: String(text),
                fingerprint: String(fingerprint),
                savedAt: Date.now(),
            }),
        );
    } catch {
        /* storage can be full or blocked */
    }
}

export function clearCachedThreadSummary(workspaceSlug, threadRef) {
    if (!workspaceSlug || !threadRef) {
        return;
    }

    try {
        localStorage.removeItem(threadSummaryStorageKey(workspaceSlug, threadRef));
    } catch {
        /* ignore */
    }
}
