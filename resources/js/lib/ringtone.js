/**
 * A small Web Audio ringtone. No audio file, no third-party service, and no
 * autoplay until the browser has seen a user gesture (unlockAudio).
 */
let context = null;
let loop = null;
let stopped = true;

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

function beep(ctx, at, frequency, duration, volume = 0.16) {
    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();

    oscillator.type = 'sine';
    oscillator.frequency.value = frequency;
    gain.gain.setValueAtTime(0.0001, at);
    gain.gain.exponentialRampToValueAtTime(volume, at + 0.03);
    gain.gain.exponentialRampToValueAtTime(0.0001, at + duration);

    oscillator.connect(gain);
    gain.connect(ctx.destination);
    oscillator.start(at);
    oscillator.stop(at + duration + 0.06);
}

function pattern() {
    const ctx = ensureContext();

    if (!ctx) {
        return;
    }

    const now = ctx.currentTime + 0.05;

    // Two soft notes, like a warm office phone rather than an alarm.
    beep(ctx, now, 660, 0.3);
    beep(ctx, now + 0.42, 880, 0.34);
}

export function startRingtone() {
    stopRingtone();
    stopped = false;
    pattern();
    loop = setInterval(() => {
        if (!stopped) {
            pattern();
        }
    }, 2500);
}

export function stopRingtone() {
    stopped = true;

    if (loop) {
        clearInterval(loop);
        loop = null;
    }
}
