import { dynaura } from 'dynaura/vue';

const kinds = {
    success: 'success',
    error: 'error',
    warn: 'warning',
    warning: 'warning',
    info: 'info',
    secondary: 'info',
    contrast: 'info',
};

/** Ink island, evergreen accents. Matches the sidebar, on light or dark pages. */
export const islandTheme = {
    background: '#101318',
    foreground: '#f6f3ea',
    muted: 'rgba(246, 243, 234, 0.64)',
    ring: 'rgba(255, 255, 255, 0.1)',
    button: 'rgba(255, 255, 255, 0.14)',
    success: '#3ddec0',
    error: '#ff6b4a',
    warning: '#f2b84b',
    info: '#7ee0c8',
    loading: '#b3aa94',
    live: '#f2b84b',
};

function avatarOf(person) {
    if (!person) {
        return undefined;
    }

    return {
        name: person.name || '',
        src: person.avatar_url || undefined,
        presence: person.online ? 'online' : undefined,
    };
}

function show(kind, { id, title, description, duration, confetti, avatar, button, progress, onClick }) {
    return dynaura[kind]({
        id,
        title,
        description,
        duration,
        confetti: Boolean(confetti),
        avatar,
        button,
        progress,
        onClick,
    });
}

/**
 * The call shape the rest of the app already uses, plus the island extras
 * (a face, a progress bar, confetti, an action). Mounted once in App.vue.
 */
export function useToast() {
    return {
        add({ severity = 'info', summary = '', detail = '', life, confetti = false, person = null, avatar = null, button, id, onClick } = {}) {
            const kind = kinds[severity] || 'info';
            const title = summary || detail || 'Notice';

            return show(kind, {
                id,
                title,
                description: summary && detail ? detail : undefined,
                duration: life ?? 4000,
                confetti,
                avatar: avatar || avatarOf(person),
                button,
                onClick,
            });
        },

        /** Sticky island with a rolling percent. Same id updates in place. */
        progress(id, { title, description, value }) {
            const ratio = Math.max(0, Math.min(1, Number(value) || 0));

            return dynaura.loading({
                id,
                title,
                description,
                progress: ratio,
                duration: null,
            });
        },

        /** Poll a 0–100 reading until `stop()` is called. */
        followPercent(id, title, readPercent, description = 'Staying on this device') {
            let last = -1;
            const paint = () => {
                const percent = Math.max(0, Math.min(100, Number(readPercent()) || 0));
                if (percent === last) {
                    return;
                }
                last = percent;
                dynaura.loading({
                    id,
                    title,
                    description,
                    progress: percent / 100,
                    duration: null,
                });
            };
            paint();
            const timer = setInterval(paint, 200);

            return () => clearInterval(timer);
        },

        loading(id, title, description) {
            return dynaura.loading({
                id,
                title,
                description,
                duration: null,
            });
        },

        /** Morph a sticky island into a finished state and let it leave. */
        finish(id, { severity = 'success', summary = 'Done', detail = '', life = 2200, confetti = false, person = null } = {}) {
            return dynaura.update(id, {
                type: kinds[severity] || 'success',
                title: summary,
                description: detail || undefined,
                progress: undefined,
                duration: life,
                confetti: Boolean(confetti),
                avatar: avatarOf(person),
            });
        },

        dismiss(id) {
            dynaura.dismiss(id);
        },

        /** A face and a line of text. Tap opens whatever `onClick` does. */
        person({ id, person, title, description, onClick, life = 5200 }) {
            return dynaura.info({
                id,
                title: title || person?.name || 'New message',
                description,
                avatar: avatarOf(person),
                duration: life,
                onClick,
            });
        },
    };
}
