/**
 * The call ringtones. Each one is drawn with Web Audio into a short buffer
 * and then looped: no audio file, no third-party service, and no autoplay
 * until the browser has seen a user gesture (unlockAudio).
 */
let context = null;
let playing = null;
let turn = 0;

const buffers = new Map();

export const RINGTONES = [
    { value: 'marimba', label: 'Marimba' },
    { value: 'classic', label: 'Classic' },
    { value: 'chime', label: 'Chime' },
];

export const DEFAULT_RINGTONE = 'marimba';

// Long buzz, short gap, like a phone on a table.
const VIBRATION = [900, 500, 900, 1100];

function ensureContext() {
    if (typeof window === 'undefined') {
        return null;
    }

    const Ctx = window.AudioContext || window.webkitAudioContext;

    if (!Ctx) {
        return null;
    }

    if (!context) {
        try {
            context = new Ctx();
        } catch {
            return null;
        }
    }

    if (context.state === 'suspended') {
        context.resume().catch(() => {});
    }

    return context;
}

export function unlockAudio() {
    ensureContext();
}

/** One oscillator with a struck envelope: fast in, slow out. */
function strike(ctx, at, { frequency, peak, attack = 0.004, decay, type = 'sine' }) {
    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();

    oscillator.type = type;
    oscillator.frequency.value = frequency;
    gain.gain.setValueAtTime(0.0001, at);
    gain.gain.linearRampToValueAtTime(peak, at + attack);
    gain.gain.exponentialRampToValueAtTime(0.0001, at + attack + decay);

    oscillator.connect(gain);
    gain.connect(ctx.destination);
    oscillator.start(at);
    oscillator.stop(at + attack + decay + 0.05);
}

/** A wooden bar: a warm body, an overtone two octaves up, and the mallet's knock. */
function mallet(ctx, at, frequency, volume = 0.5) {
    strike(ctx, at, { frequency, peak: volume, decay: 0.6 });
    strike(ctx, at, { frequency: frequency * 4, peak: volume * 0.26, attack: 0.002, decay: 0.16 });
    strike(ctx, at, { frequency: frequency * 9.2, peak: volume * 0.07, attack: 0.001, decay: 0.04 });
}

/** A small bell: clear partials that ring on after the note. */
function bell(ctx, at, frequency, volume = 0.4) {
    strike(ctx, at, { frequency, peak: volume, decay: 1.3 });
    strike(ctx, at, { frequency: frequency * 2, peak: volume * 0.34, decay: 0.8 });
    strike(ctx, at, { frequency: frequency * 3.01, peak: volume * 0.14, decay: 0.45 });
}

/** One burst of a desk phone's ringer: two pitches trading places quickly. */
function trill(ctx, at, duration) {
    const gain = ctx.createGain();
    const filter = ctx.createBiquadFilter();

    filter.type = 'lowpass';
    filter.frequency.value = 3200;
    gain.gain.setValueAtTime(0.0001, at);
    gain.gain.linearRampToValueAtTime(0.5, at + 0.012);
    gain.gain.setValueAtTime(0.5, at + duration - 0.04);
    gain.gain.linearRampToValueAtTime(0.0001, at + duration);
    filter.connect(gain);
    gain.connect(ctx.destination);

    [[1, 0.7], [0.5, 0.3]].forEach(([ratio, level]) => {
        const oscillator = ctx.createOscillator();
        const voice = ctx.createGain();
        const step = 1 / 44;

        oscillator.type = 'triangle';
        voice.gain.value = level;

        for (let time = 0, flip = 0; time < duration; time += step, flip += 1) {
            oscillator.frequency.setValueAtTime((flip % 2 ? 1320 : 1056) * ratio, at + time);
        }

        oscillator.connect(voice);
        voice.connect(filter);
        oscillator.start(at);
        oscillator.stop(at + duration + 0.02);
    });
}

const D4 = 293.66;
const D5 = 587.33;
const E5 = 659.25;
const FS5 = 739.99;
const G5 = 783.99;
const A5 = 880;
const B5 = 987.77;
const D6 = 1174.66;
const G6 = 1567.98;

const TONES = {
    marimba: {
        seconds: 3.6,
        draw(ctx) {
            [
                [0, A5], [0.15, FS5], [0.3, D5], [0.45, FS5], [0.6, A5],
                [0.9, B5], [1.05, A5], [1.2, FS5],
                [1.5, D6], [1.65, B5], [1.8, A5], [1.95, FS5], [2.1, E5],
                [2.4, D5],
            ].forEach(([at, note]) => mallet(ctx, at, note));

            mallet(ctx, 0, D4, 0.34);
            mallet(ctx, 1.5, D4, 0.34);
            mallet(ctx, 2.4, D4, 0.34);
        },
    },
    classic: {
        seconds: 3,
        draw(ctx) {
            trill(ctx, 0, 0.46);
            trill(ctx, 0.66, 0.46);
        },
    },
    chime: {
        seconds: 3.4,
        draw(ctx) {
            [[0, G5], [0.17, B5], [0.34, D6], [0.51, G6]].forEach(([at, note]) => bell(ctx, at, note));
            [[1.3, D6], [1.47, B5]].forEach(([at, note]) => bell(ctx, at, note, 0.26));
        },
    },
};

function toneFor(name) {
    return TONES[name] ? name : DEFAULT_RINGTONE;
}

/** Bring the loudest sample to a fixed level so every tone rings equally loud. */
function normalize(buffer) {
    for (let channel = 0; channel < buffer.numberOfChannels; channel += 1) {
        const samples = buffer.getChannelData(channel);
        let peak = 0;

        for (let index = 0; index < samples.length; index += 1) {
            peak = Math.max(peak, Math.abs(samples[index]));
        }

        if (peak > 0) {
            const scale = 0.85 / peak;

            for (let index = 0; index < samples.length; index += 1) {
                samples[index] *= scale;
            }
        }
    }

    return buffer;
}

function render(name, sampleRate) {
    const key = `${name}@${sampleRate}`;

    if (buffers.has(key)) {
        return buffers.get(key);
    }

    const Offline = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    const tone = TONES[name];

    const job = new Promise((resolve, reject) => {
        if (!Offline) {
            reject(new Error('This browser cannot draw the ringtone.'));

            return;
        }

        const offline = new Offline(1, Math.ceil(tone.seconds * sampleRate), sampleRate);

        tone.draw(offline);
        offline.oncomplete = (event) => resolve(event.renderedBuffer);

        // Older Safari only reports through oncomplete.
        offline.startRendering()?.then?.(resolve, reject);
    }).then(normalize);

    buffers.set(key, job);
    job.catch(() => buffers.delete(key));

    return job;
}

async function play(name, seconds, loop) {
    silence();

    const ctx = ensureContext();

    if (!ctx) {
        return;
    }

    turn += 1;
    const mine = turn;
    let buffer = null;

    try {
        buffer = await render(toneFor(name), ctx.sampleRate);
    } catch {
        return;
    }

    // Answered, declined, or replaced while the tone was being drawn.
    if (mine !== turn) {
        return;
    }

    const source = ctx.createBufferSource();
    const gain = ctx.createGain();

    source.buffer = buffer;
    source.loop = loop;
    source.connect(gain);
    gain.connect(ctx.destination);
    source.start();
    // A looping buffer runs on the audio thread, so a tab in the background
    // keeps ringing: a hidden tab's timers slow to once a minute, and a ring
    // driven by setInterval went quiet there.
    source.stop(ctx.currentTime + seconds);
    source.onended = () => {
        if (playing?.source === source) {
            playing = null;
        }
    };

    playing = { source, gain };
}

function silence() {
    turn += 1;

    if (!playing) {
        return;
    }

    try {
        playing.gain.gain.value = 0;
        playing.source.stop();
        playing.gain.disconnect();
    } catch {
        /* already stopped, or the context was closed */
    }

    playing = null;
}

let buzzing = false;

function vibrate(pattern) {
    // Android only, and only once the person has touched the page; before
    // that the browser refuses and logs a warning for every attempt.
    if (typeof navigator === 'undefined' || !navigator.vibrate || !navigator.userActivation?.hasBeenActive) {
        return false;
    }

    try {
        return navigator.vibrate(pattern);
    } catch {
        return false;
    }
}

/** Ring for `durationMs` with the chosen tone, and buzz a phone that can. */
export function startRingtone(durationMs = 45000, name = DEFAULT_RINGTONE) {
    const rounds = Math.ceil(durationMs / VIBRATION.reduce((sum, part) => sum + part, 0));

    buzzing = vibrate(Array.from({ length: rounds }, () => VIBRATION).flat());

    return play(name, durationMs / 1000, true);
}

export function stopRingtone() {
    if (buzzing) {
        vibrate(0);
        buzzing = false;
    }

    silence();
}

/** Play a tone once, for the picker in Settings. */
export function previewRingtone(name) {
    return play(name, TONES[toneFor(name)].seconds, false);
}
