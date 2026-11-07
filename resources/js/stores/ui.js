import { defineStore } from 'pinia';

const STORAGE_KEY = 'smack.preferences';

function readPreferences() {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    } catch {
        return {};
    }
}

function prefersDark() {
    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
}

export const useUiStore = defineStore('ui', {
    state: () => {
        const saved = readPreferences();

        return {
            theme: saved.theme || 'light',
            density: saved.density || 'comfortable',
            reduceMotion: Boolean(saved.reduceMotion),
            commandOpen: false,
            shortcutsOpen: false,
            mediaPreview: null,
            preferences: {
                messageSounds: Boolean(saved.messageSounds),
                desktopNotifications: Boolean(saved.desktopNotifications),
            },
        };
    },
    getters: {
        isDark: (state) => (state.theme === 'system' ? prefersDark() : state.theme === 'dark'),
    },
    actions: {
        applyTheme() {
            document.documentElement.classList.toggle('app-dark', this.isDark);
            document.documentElement.classList.toggle('reduce-motion', this.reduceMotion);
            document.documentElement.dataset.theme = this.isDark ? 'dark' : 'light';
            document.documentElement.dataset.density = this.density;
        },
        setTheme(theme) {
            this.theme = theme;
            this.save();
            this.applyTheme();
        },
        toggleTheme() {
            this.setTheme(this.isDark ? 'light' : 'dark');
        },
        setDensity(density) {
            this.density = density;
            this.save();
            this.applyTheme();
        },
        setReduceMotion(value) {
            this.reduceMotion = value;
            this.save();
            this.applyTheme();
        },
        setPreference(key, value) {
            this.preferences[key] = value;
            this.save();
        },
        openCommand() {
            this.commandOpen = true;
        },
        closeCommand() {
            this.commandOpen = false;
        },
        openMediaPreview(payload) {
            this.mediaPreview = payload;
        },
        closeMediaPreview() {
            this.mediaPreview = null;
        },
        save() {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify({
                    theme: this.theme,
                    density: this.density,
                    reduceMotion: this.reduceMotion,
                    ...this.preferences,
                }));
            } catch {
                /* storage can be blocked */
            }
        },
    },
});
