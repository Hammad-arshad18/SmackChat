<script setup>
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import api, { errorText } from '@/api';
import { searchMessages } from '@/e2e';
import { useAuthStore } from '@/stores/auth';
import { useOfficeStore } from '@/stores/office';
import { useUiStore } from '@/stores/ui';

const emit = defineEmits(['channel', 'dm', 'workspaces']);
const router = useRouter();
const toast = useToast();
const auth = useAuthStore();
const office = useOfficeStore();
const ui = useUiStore();
const query = ref('');
const results = ref([]);
const searching = ref(false);
let searchTimer;
let searchToken = 0;

watch(() => ui.commandOpen, (open) => {
    if (open) {
        query.value = '';
        results.value = [];
    }
});

watch(query, (value) => {
    clearTimeout(searchTimer);
    const needle = value.trim();

    if (needle.length < 2) {
        searchToken += 1;
        results.value = [];
        searching.value = false;
        return;
    }

    searching.value = true;
    const token = ++searchToken;
    searchTimer = setTimeout(async () => {
        try {
            const found = await searchMessages(office, needle);
            if (token === searchToken) {
                results.value = found;
            }
        } catch {
            if (token === searchToken) {
                results.value = [];
            }
        } finally {
            if (token === searchToken) {
                searching.value = false;
            }
        }
    }, 260);
});

function close() {
    ui.commandOpen = false;
}

function run(action) {
    return () => {
        close();
        action?.();
    };
}

function go(path) {
    router.push(path);
}

function goChannel(slug, messageId = null, replyId = null) {
    router.push({
        path: `/w/${office.slug}/c/${slug}`,
        query: messageId ? { m: messageId, ...(replyId ? { reply: replyId } : {}) } : {},
    });
}

async function startDm(person) {
    try {
        const { data } = await api.post(`/workspaces/${office.slug}/dms`, { user_id: person.id });
        goChannel(data.slug);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Direct message', detail: errorText(error), life: 3000 });
    }
}

async function punch(action) {
    try {
        const result = await office.punch(action);
        toast.add({
            severity: 'success',
            summary: action === 'check_in' ? 'On the clock' : 'Shift filed',
            detail: action === 'check_in' ? 'Your day has started.' : `${result.day.work_label} landed in Timesheets.`,
            life: 3000,
        });
    } catch (error) {
        toast.add({ severity: 'warn', summary: 'Attendance', detail: errorText(error), life: 3500 });
    }
}

async function markAllRead() {
    try {
        await office.markAllRead();
        toast.add({ severity: 'success', summary: 'All caught up', life: 2000 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Read state', detail: errorText(error), life: 3000 });
    }
}

const actionItems = computed(() => {
    const items = [
        {
            label: ui.isDark ? 'Switch to light theme' : 'Switch to dark theme',
            icon: ui.isDark ? 'pi pi-sun' : 'pi pi-moon',
            keywords: ['appearance', 'theme', 'color', 'dark', 'light'],
            command: run(() => ui.toggleTheme()),
        },
        {
            label: 'New channel',
            icon: 'pi pi-plus-circle',
            shortcut: 'C',
            keywords: ['create', 'room', 'channel'],
            command: run(() => emit('channel')),
        },
        {
            label: 'New direct message',
            icon: 'pi pi-send',
            shortcut: 'D',
            keywords: ['dm', 'person', 'chat'],
            command: run(() => emit('dm')),
        },
        {
            label: 'Switch workspace',
            icon: 'pi pi-box',
            keywords: ['workspace', 'company', 'switch'],
            command: run(() => emit('workspaces')),
        },
        {
            label: 'Mark everything read',
            icon: 'pi pi-check-square',
            keywords: ['inbox', 'unread', 'read', 'clear'],
            command: run(markAllRead),
        },
        {
            label: 'Today',
            icon: 'pi pi-home',
            keywords: ['home', 'dashboard', 'today'],
            command: run(() => go(`/w/${office.slug}`)),
        },
        {
            label: 'Attendance',
            icon: 'pi pi-stopwatch',
            keywords: ['hours', 'timesheet', 'clock'],
            command: run(() => go(`/w/${office.slug}/attendance`)),
        },
        {
            label: 'Schedule',
            icon: 'pi pi-calendar',
            keywords: ['office hours', 'floor clock', 'working week'],
            command: run(() => go(`/w/${office.slug}/hours`)),
        },
        {
            label: 'People',
            icon: 'pi pi-users',
            keywords: ['directory', 'team', 'members'],
            command: run(() => go(`/w/${office.slug}/members`)),
        },
        {
            label: 'Settings',
            icon: 'pi pi-sliders-h',
            keywords: ['profile', 'preferences', 'status'],
            command: run(() => go(`/w/${office.slug}/settings`)),
        },
    ];

    if (!office.today) {
        items.unshift({
            label: 'Check in',
            icon: 'pi pi-sign-in',
            keywords: ['clock', 'start', 'attendance'],
            command: run(() => punch('check_in')),
        });
    } else if (office.today.status === 'open') {
        items.unshift({
            label: 'Take a break',
            icon: 'pi pi-pause',
            keywords: ['clock', 'attendance'],
            command: run(() => punch('break_in')),
        });
        items.splice(1, 0, {
            label: 'Check out',
            icon: 'pi pi-sign-out',
            keywords: ['clock', 'end', 'attendance'],
            command: run(() => punch('check_out')),
        });
    } else if (office.today.status === 'on_break') {
        items.unshift({
            label: 'Back to work',
            icon: 'pi pi-play',
            keywords: ['clock', 'break', 'attendance'],
            command: run(() => punch('break_out')),
        });
    }

    return items;
});

const model = computed(() => {
    const groups = [];

    if (results.value.length) {
        groups.push({
            label: 'Messages',
            items: results.value.map((result) => ({
                label: result.snippet,
                snippet: `${result.channel.label} · ${result.message.user?.name || 'Someone'}`,
                icon: 'pi pi-comment',
                keywords: [result.channel.label, result.message.user?.name, result.message.text],
                meta: 'Message',
                command: run(() => goChannel(
                    result.channel.slug,
                    result.message.parent_id || result.message.id,
                    result.message.parent_id ? result.message.id : null,
                )),
            })),
        });
    }

    const channels = office.channels.map((channel) => ({
        label: channel.label,
        snippet: channel.excerpt || channel.topic || (channel.type === 'dm' ? 'Direct message' : 'Channel'),
        icon: channel.type === 'dm' ? 'pi pi-user' : channel.type === 'private' ? 'pi pi-lock' : 'pi pi-hashtag',
        keywords: [channel.excerpt, channel.topic, channel.type],
        meta: channel.unread ? `${channel.unread} unread` : channel.type,
        command: run(() => goChannel(channel.slug)),
    }));

    if (channels.length) {
        groups.push({ label: 'Conversations', items: channels });
    }

    const people = office.members
        .filter((person) => person.id !== auth.user?.id)
        .map((person) => ({
            label: person.name,
            snippet: person.status_text || person.title || (person.online ? 'Online now' : 'Away'),
            avatar: person.avatar_color,
            initial: person.name.slice(0, 1),
            online: person.online,
            keywords: [person.email, person.title, person.role],
            meta: person.online ? 'Online' : 'Away',
            command: run(() => startDm(person)),
        }));

    if (people.length) {
        groups.push({ label: 'People', items: people });
    }

    groups.push({ label: 'Actions', items: actionItems.value });

    return groups;
});
</script>

<template>
    <Dialog
        v-model:visible="ui.commandOpen"
        modal
        :show-header="false"
        :draggable="false"
        :style="{ width: 'min(46rem, 94vw)' }"
        class="command-dialog"
        @hide="query = ''"
    >
        <CommandMenu
            v-model:search="query"
            :model="model"
            placeholder="Search messages, people, or jump somewhere…"
            empty-message="Type to search across the workspace."
            empty-filter-message="Nothing matched. Try a person, a channel, or a message."
            aria-label="Command palette"
            class="command-menu"
        >
            <template #header="{ inputProps }">
                <div class="cmd-search">
                    <i class="pi pi-search" />
                    <input v-bind="inputProps" class="cmd-input" placeholder="Search messages, people, or jump somewhere…">
                    <span v-if="searching" class="cmd-spinner"><i class="pi pi-spin pi-spinner" /></span>
                    <kbd v-else>⌘K</kbd>
                </div>
            </template>

            <template #submenulabel="{ item }">
                <div class="cmd-group-label">{{ item.label }}</div>
            </template>

            <template #item="{ item, props }">
                <a v-ripple class="cmd-item" v-bind="props.action">
                    <span v-if="item.avatar" class="cmd-avatar" :style="{ background: item.avatar }">
                        {{ item.initial }}
                        <i v-if="item.online" class="online-dot" />
                    </span>
                    <span v-else class="cmd-icon"><i :class="item.icon" /></span>
                    <span class="cmd-copy">
                        <strong>{{ item.label }}</strong>
                        <small v-if="item.snippet">{{ item.snippet }}</small>
                    </span>
                    <span v-if="item.meta" class="cmd-meta">{{ item.meta }}</span>
                    <kbd v-if="item.shortcut" class="cmd-shortcut">{{ item.shortcut }}</kbd>
                </a>
            </template>

            <template #footer>
                <div class="cmd-footer">
                    <span><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
                    <span><kbd>↵</kbd> open</span>
                    <span><kbd>esc</kbd> close</span>
                    <span v-if="results.length" class="cmd-count">{{ results.length }} message{{ results.length === 1 ? '' : 's' }} found</span>
                </div>
            </template>
        </CommandMenu>
    </Dialog>
</template>
