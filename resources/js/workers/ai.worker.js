/**
 * SmackChat on-device AI worker (Transformers.js).
 *
 * Used only when Chrome's built-in Gemini Nano is unavailable or fails at runtime.
 * Every byte of inference happens here, in the browser — message text never leaves
 * the device and never touches the SmackChat API.
 *
 * The worker loads exactly one model, chosen by `ai.js` and sent in `init`. When a
 * model cannot start (ONNX `std::bad_alloc`, no WebGPU, …) the page terminates this
 * worker and starts a fresh one for the next candidate: a new worker gets a clean
 * WASM heap, so one failed session cannot break the next attempt.
 */

const IOS_IDLE_DISPOSE_MS = 60000;

const FALLBACK_MODEL = {
    id: 'onnx-community/SmolLM2-360M-Instruct-ONNX',
    label: 'SmolLM2 360M',
};

let device = { isIos: false };
let spec = null;
let transformers = null;
let activePipeline = null;
let loadingPromise = null;
let idleTimer = null;
let activeRequests = 0;
let loadingLabel = FALLBACK_MODEL.label;
const fileProgress = new Map();

function post(message) {
    self.postMessage(message);
}

function messageFor(error) {
    return String(error?.message || error || 'On-device model failed.');
}

function activeSpec() {
    return spec || { modelId: FALLBACK_MODEL.id, modelLabel: FALLBACK_MODEL.label, device: 'wasm' };
}

function isSafari() {
    const ua = typeof navigator !== 'undefined' ? navigator.userAgent : '';

    return /safari/i.test(ua) && !/chrome|chromium|crios|fxios|edg|firefox|android/i.test(ua);
}

function isFirefox() {
    const ua = typeof navigator !== 'undefined' ? navigator.userAgent : '';

    return /firefox|fxios/i.test(ua);
}

function reportProgress(info) {
    if (info?.status !== 'progress' || !info.file) {
        return;
    }

    fileProgress.set(info.file, {
        loaded: Number(info.loaded) || 0,
        total: Number(info.total) || 0,
    });

    let loaded = 0;
    let total = 0;

    for (const item of fileProgress.values()) {
        loaded += item.loaded;
        total += item.total;
    }

    const percent = total > 0 ? Math.round((loaded / total) * 100) : 0;

    post({
        type: 'download-progress',
        percent: Math.max(0, Math.min(100, percent)),
        statusText: `Fetching ${loadingLabel} model files…`,
    });
}

async function loadPipeline() {
    if (activePipeline) {
        return activePipeline;
    }

    if (!loadingPromise) {
        loadingPromise = buildPipeline().finally(() => {
            loadingPromise = null;
        });
    }

    return loadingPromise;
}

async function webgpuDtype() {
    try {
        const adapter = await navigator.gpu?.requestAdapter?.();

        return adapter?.features?.has('shader-f16') ? 'q4f16' : 'q4';
    } catch {
        return 'q4';
    }
}

/**
 * Compile the WebGPU shaders and kernels with a one-token run so the user's first
 * real summary or draft does not pay the compilation cost.
 */
async function warmUp(pipeline) {
    try {
        await pipeline(
            [
                { role: 'system', content: 'Reply with OK.' },
                { role: 'user', content: 'ping' },
            ],
            { max_new_tokens: 1, do_sample: false },
        );
    } catch {
        /* warm-up is best effort */
    }
}

async function buildPipeline() {
    // Pulled in lazily so a device that never needs this worker never fetches the bundle.
    transformers ??= await import('@huggingface/transformers');
    transformers.env.allowLocalModels = false;
    transformers.env.useBrowserCache = true;

    // Safari and Firefox keep a tighter lid on worker memory than Chrome; one wasm thread
    // avoids multiplying the buffers a single session needs.
    if (device.isIos || isSafari() || isFirefox()) {
        try {
            transformers.env.backends.onnx.wasm.numThreads = 1;
        } catch {
            /* older runtime without a configurable wasm backend */
        }
    }

    const active = activeSpec();
    loadingLabel = active.modelLabel;
    fileProgress.clear();

    const options = {
        dtype: active.device === 'webgpu' ? await webgpuDtype() : 'q4',
        device: active.device,
        progress_callback: reportProgress,
    };

    if (active.device !== 'webgpu') {
        // WASM sessions are the memory-constrained path: skip ORT's pre-allocated arena
        // and per-shape memory pattern so the model fits on low-memory machines.
        options.session_options = {
            enableCpuMemArena: false,
            enableMemPattern: false,
        };
    }

    post({
        type: 'download-progress',
        percent: 0,
        statusText: `Loading ${active.modelLabel} on this device…`,
    });

    activePipeline = await transformers.pipeline('text-generation', active.modelId, options);

    post({ type: 'download-complete' });
    scheduleIdleDispose();

    if (active.device === 'webgpu') {
        // Fire and forget: generation calls queue behind it, but the badge turns ready now.
        warmUp(activePipeline);
    }

    return activePipeline;
}

function scheduleIdleDispose() {
    if (!device.isIos) {
        return;
    }

    clearTimeout(idleTimer);
    idleTimer = null;

    if (activeRequests > 0 || loadingPromise) {
        return;
    }

    idleTimer = setTimeout(() => {
        idleTimer = null;

        if (activeRequests > 0 || loadingPromise) {
            scheduleIdleDispose();
            return;
        }

        disposePipeline();
    }, IOS_IDLE_DISPOSE_MS);
}

async function disposePipeline() {
    clearTimeout(idleTimer);
    idleTimer = null;

    const pipeline = activePipeline;
    activePipeline = null;
    fileProgress.clear();

    if (!pipeline) {
        return;
    }

    try {
        await pipeline.dispose?.();
    } catch {
        /* the runtime may already be gone after a tab freeze */
    }

    post({ type: 'unloaded' });
}

function clampTokens(value) {
    const tokens = Number(value) || 220;

    return Math.max(32, Math.min(512, Math.round(tokens)));
}

async function generate({ requestId, messages, maxNewTokens, maxChars }) {
    activeRequests += 1;
    clearTimeout(idleTimer);
    idleTimer = null;

    try {
        const pipeline = await loadPipeline();
        const { TextStreamer } = transformers;
        const cap = Number(maxChars) || 6000;
        const trimmed = (Array.isArray(messages) ? messages : []).map((message) => {
            const content = String(message?.content ?? '');
            const bounded = content.length > cap ? content.slice(content.length - cap) : content;

            return { role: message?.role === 'system' ? 'system' : 'user', content: bounded };
        });

        let streamed = '';
        const streamer = new TextStreamer(pipeline.tokenizer, {
            skip_prompt: true,
            skip_special_tokens: true,
            callback_function: (text) => {
                if (!text) {
                    return;
                }

                streamed += text;
                post({ type: 'chunk', requestId, text });
            },
        });

        const output = await pipeline(trimmed, {
            max_new_tokens: clampTokens(maxNewTokens),
            do_sample: false,
            streamer,
        });

        const generated = output?.[0]?.generated_text;
        const text = Array.isArray(generated)
            ? String(generated.at(-1)?.content || '')
            : String(generated || '');

        post({ type: 'done', requestId, text: (text || streamed).trim() });
    } finally {
        activeRequests = Math.max(0, activeRequests - 1);
        scheduleIdleDispose();
    }
}

self.addEventListener('message', async (event) => {
    const data = event.data || {};

    if (data.type === 'init') {
        device = { isIos: !!data.isIos };
        spec = {
            modelId: String(data.modelId || FALLBACK_MODEL.id),
            modelLabel: String(data.modelLabel || FALLBACK_MODEL.label),
            device: data.device === 'webgpu' ? 'webgpu' : 'wasm',
        };
        return;
    }

    if (data.type === 'load') {
        try {
            await loadPipeline();
        } catch (error) {
            post({ type: 'load-error', message: messageFor(error) });
        }
        return;
    }

    if (data.type === 'generate') {
        try {
            await generate(data);
        } catch (error) {
            post({ type: 'error', requestId: data.requestId, message: messageFor(error) });
        }
        return;
    }

    if (data.type === 'unload') {
        await disposePipeline();
    }
});

self.addEventListener('unhandledrejection', (event) => {
    post({ type: 'load-error', message: messageFor(event.reason) });
});
