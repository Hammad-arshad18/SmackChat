/**
 * On-device AI helpers for SmackChat (Chrome Built-in Gemini Nano).
 * Inference stays in the browser — never send ciphertext or plaintext to a server.
 */

const MAX_SUMMARY_CHARS = 15000;
const TOKEN_PREFIX = '__SMACK_TOKEN_';
const TOKEN_SUFFIX = '__';
const THREAD_SUMMARY_CACHE_PREFIX = 'smack.ai.threadSummary.v1.';

const UNAVAILABLE = 'unavailable';
const AVAILABLE = 'available';
const DOWNLOADABLE = 'downloadable';
const DOWNLOADING = 'downloading';

function apiSurface(name) {
    if (typeof self === 'undefined') {
        return null;
    }

    if (typeof self[name] === 'function' || (self[name] && typeof self[name].create === 'function')) {
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

/**
 * @returns {Promise<{ canSummarize: boolean, canRewrite: boolean, canWrite: boolean, status: { summarizer: string, rewriter: string, writer: string, prompt: string } }>}
 */
export async function getAiCapabilities() {
    const [summarizer, rewriter, writer, prompt] = await Promise.all([
        probeAvailability(apiSurface('Summarizer')),
        probeAvailability(apiSurface('Rewriter')),
        probeAvailability(apiSurface('Writer')),
        probeAvailability(apiSurface('LanguageModel')),
    ]);

    return {
        canSummarize: isUsable(summarizer) || isUsable(prompt),
        canRewrite: isUsable(rewriter) || isUsable(prompt),
        canWrite: isUsable(writer) || isUsable(prompt),
        status: { summarizer, rewriter, writer, prompt },
    };
}

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
    next = next.replace(/\bwww\.[^\s<>()"]+/gi, stash);
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

function progressMonitor(onDownloadProgress) {
    if (typeof onDownloadProgress !== 'function') {
        return undefined;
    }

    return (monitor) => {
        monitor.addEventListener('downloadprogress', (event) => {
            const loaded = Number(event?.loaded);
            const total = Number(event?.total);
            let ratio = Number.isFinite(loaded) ? loaded : 0;

            if (Number.isFinite(total) && total > 0) {
                ratio = loaded / total;
            } else if (ratio > 1) {
                ratio = ratio / 100;
            }

            onDownloadProgress(Math.max(0, Math.min(100, Math.round(ratio * 100))));
        });
    };
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

function destroySession(session) {
    try {
        session?.destroy?.();
    } catch {
        /* already released */
    }
}

function grammarPrompt(text) {
    return [
        'Fix grammar and spelling only. Keep meaning, mentions, URLs, and code placeholders unchanged.',
        'Return only the corrected message — no preamble.',
        '',
        text,
    ].join('\n');
}

function rewritePrompt(text, { tone, length, action }) {
    if (action === 'fix-grammar') {
        return grammarPrompt(text);
    }

    const toneHint = {
        'more-formal': 'Make it more professional and polished.',
        'more-casual': 'Make it more casual and friendly.',
        'as-is': 'Keep the tone similar.',
    }[tone] || 'Keep the tone similar.';

    const lengthHint = {
        shorter: 'Make it shorter and tighter.',
        longer: 'Add a little more clarity if needed.',
        'as-is': 'Keep roughly the same length.',
    }[length] || 'Keep roughly the same length.';

    return [
        'Rewrite this workplace chat message for SmackChat.',
        toneHint,
        lengthHint,
        'Preserve meaning. Keep placeholders like __SMACK_TOKEN_0__ exactly as written.',
        'Return only the rewritten message — no preamble.',
        '',
        text,
    ].join('\n');
}

function writerTone(tone) {
    if (tone === 'more-formal' || tone === 'formal' || tone === 'professional') {
        return 'formal';
    }

    if (tone === 'more-casual' || tone === 'casual') {
        return 'casual';
    }

    return 'neutral';
}

function writePrompt(context, { tone, length }) {
    const toneHint = {
        formal: 'Use a clear, professional workplace tone.',
        casual: 'Use a friendly, casual workplace tone.',
        neutral: 'Use a natural workplace chat tone.',
    }[writerTone(tone)] || 'Use a natural workplace chat tone.';

    const lengthHint = {
        short: 'Keep it to a short chat message (1–3 sentences).',
        medium: 'Keep it to a concise paragraph.',
        long: 'A slightly longer message is fine, but stay skimmable.',
    }[length] || 'Keep it to a short chat message (1–3 sentences).';

    return [
        'Write a workplace chat message for SmackChat from the user\'s notes.',
        toneHint,
        lengthHint,
        'Do not invent facts beyond the notes. Keep @mentions, URLs, and code placeholders exactly as written.',
        'Return only the message — no subject line, no preamble, no quotation marks wrapping the whole reply.',
        '',
        context,
    ].join('\n');
}

/**
 * Draft a message locally from user context with Writer, falling back to LanguageModel.
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

    const protectedContext = protectTokens(source);
    const input = protectedContext.text;
    const monitor = progressMonitor(onDownloadProgress);
    const writerApi = apiSurface('Writer');
    const promptApi = apiSurface('LanguageModel');
    let session = null;

    try {
        if (writerApi) {
            try {
                session = await writerApi.create({
                    tone: writerTone(tone),
                    length,
                    format: 'plain-text',
                    sharedContext: 'Workplace chat message in SmackChat. Keep it concise and human.',
                    monitor,
                });

                let result = '';
                if (typeof session.writeStreaming === 'function') {
                    result = await readStream(session.writeStreaming(input), onChunk);
                } else {
                    result = await session.write(input);
                    onChunk?.(result);
                }

                return restoreTokens(String(result || '').trim(), protectedContext.tokens);
            } catch (error) {
                destroySession(session);
                session = null;
                if (!promptApi) {
                    throw error;
                }
            }
        }

        if (!promptApi) {
            throw new Error('On-device writing is unavailable in this browser.');
        }

        session = await promptApi.create({
            systemPrompt: 'You write short workplace chat messages from brief notes. Stay faithful to the notes. Never invent facts. Preserve placeholders exactly. Return only the message.',
            monitor,
        });

        const prompt = writePrompt(input, { tone, length });
        let result = '';

        if (typeof session.promptStreaming === 'function') {
            result = await readStream(session.promptStreaming(prompt), onChunk);
        } else {
            result = await session.prompt(prompt);
            onChunk?.(result);
        }

        return restoreTokens(String(result || '').trim(), protectedContext.tokens);
    } finally {
        destroySession(session);
    }
}

/**
 * Rewrite a draft locally with Rewriter, falling back to LanguageModel.
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

    const protectedDraft = protectTokens(source);
    const input = protectedDraft.text;
    const monitor = progressMonitor(onDownloadProgress);
    const rewriterApi = apiSurface('Rewriter');
    const promptApi = apiSurface('LanguageModel');
    let session = null;

    try {
        const preferPrompt = action === 'fix-grammar' || !rewriterApi;

        if (!preferPrompt && rewriterApi) {
            try {
                session = await rewriterApi.create({
                    tone: action === 'fix-grammar' ? 'as-is' : tone,
                    length: action === 'fix-grammar' ? 'as-is' : length,
                    format: 'markdown',
                    sharedContext: 'Workplace chat message in SmackChat.',
                    monitor,
                });

                let result = '';
                if (typeof session.rewriteStreaming === 'function') {
                    result = await readStream(session.rewriteStreaming(input), onChunk);
                } else {
                    result = await session.rewrite(input);
                    onChunk?.(result);
                }

                return restoreTokens(String(result || '').trim(), protectedDraft.tokens);
            } catch (error) {
                destroySession(session);
                session = null;
                if (!promptApi) {
                    throw error;
                }
            }
        }

        if (!promptApi) {
            throw new Error('On-device rewrite is unavailable in this browser.');
        }

        session = await promptApi.create({
            systemPrompt: 'You rewrite short workplace chat messages. Stay faithful to the user\'s intent. Never invent facts. Preserve placeholders exactly.',
            monitor,
        });

        const prompt = rewritePrompt(input, { tone, length, action });
        let result = '';

        if (typeof session.promptStreaming === 'function') {
            result = await readStream(session.promptStreaming(prompt), onChunk);
        } else {
            result = await session.prompt(prompt);
            onChunk?.(result);
        }

        return restoreTokens(String(result || '').trim(), protectedDraft.tokens);
    } finally {
        destroySession(session);
    }
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

function summaryPrompt(transcript, { type, length }) {
    const style = type === 'tldr'
        ? 'Write a short TL;DR paragraph.'
        : type === 'teaser'
            ? 'Write a one-sentence teaser.'
            : 'Write a concise bulleted list of key points.';

    const size = length === 'long'
        ? 'Keep it thorough but still skimmable.'
        : length === 'medium'
            ? 'Keep it medium length.'
            : 'Keep it short.';

    return [
        'Summarize this internal company chat transcript for someone catching up.',
        style,
        size,
        'Use only information present in the transcript. Return markdown only.',
        '',
        transcript,
    ].join('\n');
}

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

/**
 * Summarize already-decrypted messages locally with Summarizer / LanguageModel.
 */
export async function summarizeMessagesLocally(
    messages,
    { type = 'key-points', length = 'short' } = {},
    { onChunk, onDownloadProgress } = {},
) {
    const transcript = formatTranscript(messages);
    if (!transcript) {
        return '';
    }

    const monitor = progressMonitor(onDownloadProgress);
    const summarizerApi = apiSurface('Summarizer');
    const promptApi = apiSurface('LanguageModel');
    let session = null;

    try {
        if (summarizerApi) {
            try {
                session = await summarizerApi.create({
                    type,
                    format: 'markdown',
                    length,
                    sharedContext: 'Internal company chat transcript.',
                    monitor,
                });

                let result = '';
                if (typeof session.summarizeStreaming === 'function') {
                    result = await readStream(session.summarizeStreaming(transcript), onChunk);
                } else {
                    result = await session.summarize(transcript);
                    onChunk?.(result);
                }

                return String(result || '').trim();
            } catch (error) {
                destroySession(session);
                session = null;
                if (!promptApi) {
                    throw error;
                }
            }
        }

        if (!promptApi) {
            throw new Error('On-device summarization is unavailable in this browser.');
        }

        session = await promptApi.create({
            systemPrompt: 'You summarize internal company chat transcripts. Be accurate, concise, and private. Use markdown.',
            monitor,
        });

        const prompt = summaryPrompt(transcript, { type, length });
        let result = '';

        if (typeof session.promptStreaming === 'function') {
            result = await readStream(session.promptStreaming(prompt), onChunk);
        } else {
            result = await session.prompt(prompt);
            onChunk?.(result);
        }

        return String(result || '').trim();
    } finally {
        destroySession(session);
    }
}
