<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import api, { errorText } from '@/api';
import ShiftWidget from '@/components/ShiftWidget.vue';
import { useAuthStore } from '@/stores/auth';
import { useOfficeStore } from '@/stores/office';
import {
    currentMonth,
    formatFullDay,
    formatWeekday,
    greeting,
    isoDate,
    minutesLabel,
    shiftTotals,
    startOfWeek,
    timeAgo,
    wallClockLabel,
} from '@/time';

const office = useOfficeStore();
const auth = useAuthStore();
const router = useRouter();
const toast = useToast();
const loading = ref(true);
const schedule = ref(null);
const now = ref(Date.now());
let tick;

const firstName = computed(() => auth.user.name.split(' ')[0]);
const myRows = computed(() => office.monthRows.filter((row) => row.user_id === auth.user.id));
const weekStart = computed(() => startOfWeek());
const weekDays = computed(() => {
    const start = weekStart.value;
    const rows = [];

    for (let index = 0; index < 7; index += 1) {
        const date = new Date(start);
        date.setDate(start.getDate() + index);
        rows.push(date);
    }

    return rows;
});
const liveWorkMinutes = computed(() => {
    if (!office.today || office.today.status === 'completed') {
        return office.today?.work_minutes || 0;
    }

    return Math.floor(shiftTotals(office.today.events || [], now.value).workSeconds / 60);
});
const rowFor = (date) => myRows.value.find((row) => row.work_date === isoDate(date));
const minutesFor = (date) => {
    const row = rowFor(date);

    if (!row) {
        return isoDate(date) === isoDate() ? liveWorkMinutes.value : 0;
    }

    if (isoDate(date) === isoDate() && row.status !== 'completed') {
        return Math.max(row.work_minutes || 0, liveWorkMinutes.value);
    }

    return row.work_minutes || 0;
};
const weekMinutes = computed(() => weekDays.value.reduce((total, date) => total + minutesFor(date), 0));
const weekDaysWorked = computed(() => weekDays.value.filter((date) => minutesFor(date) > 0).length);
const monthMinutes = computed(() => myRows.value.reduce((total, row) => total + (row.work_minutes || 0), 0) + (office.today && office.today.status !== 'completed' ? liveWorkMinutes.value : 0));
const expectedWeekMinutes = computed(() => (office.workspace?.work_days?.length || 5) * 8 * 60);
const weekProgress = computed(() => Math.min(100, Math.round((weekMinutes.value / expectedWeekMinutes.value) * 100)));
const weekMax = computed(() => Math.max(8 * 60, ...weekDays.value.map((date) => minutesFor(date))));
const barHeight = (date) => {
    const minutes = minutesFor(date);

    if (!minutes) return 3;

    return Math.max(6, Math.round((minutes / weekMax.value) * 100));
};
const dayName = (date) => formatWeekday(date);
const dayNumber = (date) => new Date(date).getDate();
const weekBars = computed(() => weekDays.value.map((date) => ({
    date,
    label: dayName(date),
    number: dayNumber(date),
    minutes: minutesFor(date),
    height: barHeight(date),
    isToday: isoDate(date) === isoDate(),
})));
const totalUnread = computed(() => office.totalUnread);
const catchUp = computed(() => office.catchUp);
const online = computed(() => office.onlineMembers.filter((person) => person.id !== auth.user.id));
const others = computed(() => office.members.filter((person) => person.id !== auth.user.id));

const nextHoliday = computed(() => {
    if (!schedule.value?.holidays?.length) return null;

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const items = schedule.value.holidays
        .map((holiday) => {
            const date = new Date(`${holiday.observed_on}T12:00:00`);

            if (holiday.repeats_yearly && date < today) {
                date.setFullYear(today.getFullYear());
                if (date < today) {
                    date.setFullYear(today.getFullYear() + 1);
                }
            }

            return { ...holiday, date };
        })
        .filter((holiday) => holiday.date >= today)
        .sort((a, b) => a.date - b.date);

    return items[0] || null;
});

const summary = computed(() => {
    if (office.today?.status === 'open') {
        return `You're on the clock with ${minutesLabel(liveWorkMinutes.value)} in the tank. Make the day count.`;
    }

    if (office.today?.status === 'on_break') {
        return 'You are on a break. The floor clock is paused — enjoy it.';
    }

    if (office.today?.status === 'completed') {
        return `Your shift is filed: ${office.today.work_label} of work. Here is what is moving across the workspace.`;
    }

    if (office.today?.holiday_name) {
        return `${office.today.holiday_name} today. No need to punch the clock.`;
    }

    return `A fresh ${formatFullDay()}. Check in when you are ready, or catch up on what the team is doing.`;
});

const meterValue = computed(() => [
    { label: 'Worked', value: weekMinutes.value, color: '#0e7c66' },
    { label: 'Target', value: Math.max(0, expectedWeekMinutes.value - weekMinutes.value), color: '#e4dfd2' },
]);

const officeWindow = computed(() => {
    if (!office.workspace) return '';

    return `${wallClockLabel(office.workspace.work_start)}–${wallClockLabel(office.workspace.work_end)} · ${office.workspace.timezone}`;
});

onMounted(async () => {
    try {
        await Promise.all([
            office.loadMonth(currentMonth()),
            api.get(`/workspaces/${office.slug}/schedule`).then(({ data }) => { schedule.value = data.schedule; }),
        ]);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Today', detail: errorText(error), life: 3000 });
    } finally {
        loading.value = false;
    }

    tick = setInterval(() => { now.value = Date.now(); }, 30000);
});

onBeforeUnmount(() => clearInterval(tick));

function open(slug) {
    router.push(`/w/${office.slug}/c/${slug}`);
}

async function startDm(person) {
    try {
        const { data } = await api.post(`/workspaces/${office.slug}/dms`, { user_id: person.id });
        open(data.slug);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Direct message', detail: errorText(error), life: 3000 });
    }
}
</script>

<template>
    <div class="page today-page">
        <header class="today-head">
            <div>
                <p class="eyebrow">{{ formatFullDay() }} <span class="dot-sep">·</span> {{ officeWindow }}</p>
                <h1 class="display-title">{{ greeting() }}, {{ firstName }} <span class="title-spark">✦</span></h1>
                <p class="lede">{{ summary }}</p>
            </div>
            <div class="today-actions">
                <Button :label="office.manages ? 'Invite someone' : 'People'" :icon="office.manages ? 'pi pi-user-plus' : 'pi pi-users'" severity="secondary" outlined @click="router.push(`/w/${office.slug}/members`)" />
                <Button label="Open attendance" icon="pi pi-arrow-up-right" @click="router.push(`/w/${office.slug}/attendance`)" />
            </div>
        </header>

        <div v-if="loading" class="bento">
            <Skeleton v-for="index in 5" :key="index" height="16rem" border-radius="24px" class="bento-card" />
        </div>

        <div v-else class="bento">
            <section class="bento-card hero-shift-card">
                <ShiftWidget variant="hero" />
            </section>

            <section class="bento-card pulse-card">
                <header class="card-head">
                    <div>
                        <p class="card-kicker">Team pulse</p>
                        <h2>{{ online.length }} online now</h2>
                    </div>
                    <RouterLink :to="`/w/${office.slug}/members`" class="text-link">Directory <i class="pi pi-arrow-right" /></RouterLink>
                </header>
                <div v-if="online.length" class="pulse-list">
                    <button v-for="person in online.slice(0, 5)" :key="person.id" type="button" class="pulse-row" @click="startDm(person)">
                        <span class="presence-avatar online">
                            <Avatar :label="person.name.slice(0, 1)" shape="circle" :style="{ background: person.avatar_color, color: '#fff' }" />
                        </span>
                        <span>
                            <strong>{{ person.name }}</strong>
                            <small>{{ person.status_text || person.title || 'Available' }}</small>
                        </span>
                        <i class="pi pi-send" />
                    </button>
                </div>
                <div v-else class="empty-mini">
                    <i class="pi pi-moon" />
                    <p>The floor is quiet. Everyone is away right now.</p>
                </div>
                <div v-if="others.length > online.length" class="pulse-more">
                    + {{ others.length - online.length }} offline teammate{{ others.length - online.length === 1 ? '' : 's' }}
                </div>
            </section>

            <section class="bento-card catchup-card">
                <header class="card-head">
                    <div>
                        <p class="card-kicker">Needs you</p>
                        <h2>{{ totalUnread ? `${totalUnread} unread message${totalUnread === 1 ? '' : 's'}` : 'Inbox zero' }}</h2>
                    </div>
                    <Button v-if="totalUnread" label="Mark all read" link size="small" @click="office.markAllRead()" />
                </header>
                <div v-if="catchUp.length" class="catchup-list">
                    <button v-for="channel in catchUp.slice(0, 4)" :key="channel.id" type="button" class="catchup-row" @click="open(channel.slug)">
                        <span class="conv-icon" :class="`type-${channel.type}`">
                            <i :class="channel.type === 'dm' ? 'pi pi-user' : channel.type === 'private' ? 'pi pi-lock' : 'pi pi-hashtag'" />
                        </span>
                        <span class="catchup-copy">
                            <span>
                                <strong>{{ channel.label }}</strong>
                                <time>{{ timeAgo(channel.last_at) }}</time>
                            </span>
                            <small>{{ channel.excerpt || 'New messages' }}</small>
                        </span>
                        <span class="unread-count">{{ channel.unread }}</span>
                    </button>
                </div>
                <div v-else class="empty-mini">
                    <i class="pi pi-check-circle" />
                    <p>Everything is read. Start a conversation or check in on a teammate.</p>
                </div>
            </section>

            <section class="bento-card week-card">
                <header class="card-head">
                    <div>
                        <p class="card-kicker">This week</p>
                        <h2>{{ minutesLabel(weekMinutes) }} <span class="muted">/ {{ minutesLabel(expectedWeekMinutes) }}</span></h2>
                    </div>
                    <Tag :value="`${weekProgress}%`" severity="success" />
                </header>
                <MeterGroup :value="meterValue" :max="expectedWeekMinutes" :label-position="'start'" class="week-meter" />
                <div class="week-chart">
                    <div v-for="bar in weekBars" :key="bar.label" class="week-bar-wrap" v-tooltip.top="`${bar.label} · ${minutesLabel(bar.minutes)}`">
                        <div class="week-bar-track">
                            <span class="week-bar" :class="{ today: bar.isToday, filled: bar.minutes > 0 }" :style="{ height: `${bar.height}%` }" />
                        </div>
                        <span class="week-bar-label" :class="{ today: bar.isToday }">{{ bar.label }}</span>
                        <span class="week-bar-number">{{ bar.number }}</span>
                    </div>
                </div>
                <div class="week-foot">
                    <span><strong>{{ weekDaysWorked }}</strong> day{{ weekDaysWorked === 1 ? '' : 's' }} with hours</span>
                    <span><strong>{{ minutesLabel(monthMinutes) }}</strong> this month</span>
                    <button type="button" class="text-link" @click="router.push(`/w/${office.slug}/attendance`)">Attendance <i class="pi pi-arrow-right" /></button>
                </div>
            </section>

            <section class="bento-card next-card">
                <header class="card-head">
                    <div>
                        <p class="card-kicker">Next up</p>
                        <h2>{{ schedule?.today?.office_open ? 'Office open today' : 'No office hours today' }}</h2>
                    </div>
                    <RouterLink :to="`/w/${office.slug}/hours`" class="icon-btn ghost" aria-label="Edit schedule"><i class="pi pi-pencil" /></RouterLink>
                </header>
                <div class="next-list">
                    <div class="next-row">
                        <span class="next-icon"><i class="pi pi-clock" /></span>
                        <span><strong>{{ officeWindow }}</strong><small>Working window</small></span>
                    </div>
                    <div class="next-row">
                        <span class="next-icon"><i class="pi pi-building" /></span>
                        <span>
                            <strong>{{ schedule?.today?.office_open ? 'Open now' : 'Closed' }}</strong>
                            <small>{{ schedule?.today?.holiday || (schedule?.today?.non_working_day ? 'Outside the working week' : 'Standard week') }}</small>
                        </span>
                    </div>
                    <div class="next-row">
                        <span class="next-icon accent"><i class="pi pi-sun" /></span>
                        <span>
                            <strong>{{ nextHoliday ? nextHoliday.name : 'No holiday scheduled' }}</strong>
                            <small>{{ nextHoliday ? formatFullDay(nextHoliday.date) : 'Add one in the schedule' }}</small>
                        </span>
                    </div>
                </div>
                <div class="next-actions">
                    <Button label="People" icon="pi pi-users" severity="secondary" outlined size="small" @click="router.push(`/w/${office.slug}/members`)" />
                    <Button label="Schedule" icon="pi pi-calendar" severity="secondary" outlined size="small" @click="router.push(`/w/${office.slug}/hours`)" />
                </div>
            </section>
        </div>
    </div>
</template>
