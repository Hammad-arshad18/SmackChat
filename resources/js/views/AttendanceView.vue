<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import api, { errorText } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useOfficeStore } from '@/stores/office';
import {
    currentMonth,
    formatClock,
    formatDay,
    formatFullDay,
    formatMonth,
    isoDate,
    minutesLabel,
    shiftMonth,
} from '@/time';

const route = useRoute();
const router = useRouter();
const office = useOfficeStore();
const auth = useAuthStore();
const toast = useToast();

const tab = ref('overview');
const month = ref(route.query.month && /^\d{4}-\d{2}$/.test(route.query.month) ? route.query.month : currentMonth());
const rows = ref([]);
const loading = ref(true);
const scope = ref(auth.user.id);
const search = ref('');
const statusFilter = ref(null);
const selectedRow = ref(null);
const detailOpen = ref(false);

const statusOptions = [
    { label: 'All statuses', value: null },
    { label: 'Open', value: 'open' },
    { label: 'On break', value: 'on_break' },
    { label: 'Complete', value: 'completed' },
];

const manager = computed(() => office.manages);
const people = computed(() => {
    const seen = new Map();
    rows.value.forEach((row) => {
        if (row.user_id && !seen.has(row.user_id)) {
            seen.set(row.user_id, {
                id: row.user_id,
                name: row.user_name || office.members.find((person) => person.id === row.user_id)?.name || `Person ${row.user_id}`,
                color: office.members.find((person) => person.id === row.user_id)?.avatar_color || '#0e7c66',
            });
        }
    });
    office.members.forEach((person) => {
        if (!seen.has(person.id)) {
            seen.set(person.id, { id: person.id, name: person.name, color: person.avatar_color });
        }
    });

    return [...seen.values()];
});
const scopeOptions = computed(() => [
    ...(manager.value ? [{ id: 0, name: 'Everyone', color: '#25231f' }] : []),
    ...people.value,
]);
const scopeName = computed(() => scopeOptions.value.find((person) => person.id === scope.value)?.name || 'You');
const scopeRows = computed(() => (scope.value === 0 ? rows.value : rows.value.filter((row) => row.user_id === scope.value)));
const completedRows = computed(() => scopeRows.value.filter((row) => row.status === 'completed'));
const minutesOf = (row) => row.counted_minutes ?? row.work_minutes ?? 0;
const totalMinutes = computed(() => scopeRows.value.reduce((total, row) => total + minutesOf(row), 0));
const overtimeMinutes = computed(() => scopeRows.value.reduce((total, row) => total + (row.overtime_minutes || 0), 0));
const breakMinutes = computed(() => scopeRows.value.reduce((total, row) => total + (row.break_minutes || 0), 0));
const daysWorked = computed(() => new Set(scopeRows.value.filter((row) => row.work_minutes > 0 || row.status !== 'idle').map((row) => row.work_date)).size);
const avgMinutes = computed(() => (completedRows.value.length ? Math.round(completedRows.value.reduce((total, row) => total + row.work_minutes, 0) / completedRows.value.length) : 0));
const openToday = computed(() => rows.value.filter((row) => row.status === 'open' || row.status === 'on_break'));

const teamSummary = computed(() => {
    const map = new Map();

    rows.value.forEach((row) => {
        if (!map.has(row.user_id)) {
            const person = office.members.find((member) => member.id === row.user_id);
            map.set(row.user_id, {
                id: row.user_id,
                name: row.user_name || person?.name || 'Teammate',
                color: person?.avatar_color || '#0e7c66',
                avatar_color: person?.avatar_color || '#0e7c66',
                avatar_url: person?.avatar_url || null,
                title: person?.title,
                online: person?.online,
                minutes: 0,
                overtime: 0,
                breaks: 0,
                days: 0,
                open: false,
            });
        }

        const entry = map.get(row.user_id);
        const worked = row.counted_minutes ?? row.work_minutes ?? 0;
        entry.minutes += worked;
        entry.overtime += row.overtime_minutes || 0;
        entry.breaks += row.break_minutes || 0;
        if (worked > 0 || row.status === 'open' || row.status === 'on_break') entry.days += 1;
        if (row.status === 'open' || row.status === 'on_break') entry.open = true;
    });

    return [...map.values()].sort((a, b) => b.minutes - a.minutes);
});

const rowForDate = (date) => scopeRows.value.find((row) => row.work_date === date);

const heatmap = computed(() => {
    const [year, part] = month.value.split('-').map(Number);
    const first = new Date(year, part - 1, 1);
    const offset = (first.getDay() + 6) % 7;
    const days = new Date(year, part, 0).getDate();
    const cells = Array.from({ length: offset }, () => null);

    for (let day = 1; day <= days; day += 1) {
        const date = `${month.value}-${String(day).padStart(2, '0')}`;
        const row = rowForDate(date);
        const minutes = row?.counted_minutes ?? row?.work_minutes ?? 0;
        cells.push({
            date,
            day,
            minutes,
            row,
            level: !minutes ? 'none' : minutes < 180 ? 'low' : minutes < 360 ? 'mid' : minutes < 480 ? 'high' : 'deep',
            weekend: [0, 6].includes(new Date(`${date}T12:00:00`).getDay()),
            today: date === isoDate(),
        });
    }

    return cells;
});
const heatmapWeekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const filteredRows = computed(() => rows.value
    .filter((row) => (statusFilter.value ? row.status === statusFilter.value : true))
    .filter((row) => {
        const needle = search.value.trim().toLowerCase();
        if (!needle) return true;
        return `${row.user_name || ''} ${row.work_date} ${row.status}`.toLowerCase().includes(needle);
    })
    .sort((a, b) => `${b.work_date}${b.user_id}`.localeCompare(`${a.work_date}${a.user_id}`)));

onMounted(load);

watch(month, (value) => {
    router.replace({ query: { ...route.query, month: value } });
    load();
});

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get(`/workspaces/${office.slug}/attendance`, { params: { month: month.value } });
        rows.value = data.rows;
        if (!manager.value) {
            scope.value = auth.user.id;
        }
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Attendance', detail: errorText(error), life: 3500 });
    } finally {
        loading.value = false;
    }
}

function moveMonth(direction) {
    month.value = shiftMonth(month.value, direction);
}

function thisMonth() {
    month.value = currentMonth();
}

async function download() {
    try {
        const response = await api.get(`/workspaces/${office.slug}/attendance/export`, {
            params: { month: month.value },
            responseType: 'blob',
        });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = `attendance-${office.slug}-${month.value}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Export', detail: errorText(error), life: 3000 });
    }
}

function statusSeverity(status) {
    if (status === 'completed') return 'success';
    if (status === 'on_break') return 'warn';
    if (status === 'open') return 'info';

    return 'secondary';
}

function statusLabel(status) {
    if (status === 'completed') return 'Complete';
    if (status === 'on_break') return 'On break';
    if (status === 'open') return 'Open';

    return 'Not started';
}

function notes(row) {
    return [row.holiday_name, row.non_working_day ? 'Off day' : null, row.outside_hours ? 'Outside hours' : null].filter(Boolean).join(' · ');
}

function shiftLabel(type) {
    return {
        check_in: 'Checked in',
        break_in: 'Break started',
        break_out: 'Back to work',
        check_out: 'Checked out',
    }[type] || type;
}

function selectPerson(personId) {
    scope.value = personId;
    tab.value = 'overview';
}

function inspectRow(row) {
    selectedRow.value = row;
    detailOpen.value = true;
}
</script>

<template>
    <div class="page attendance-page">
        <header class="page-head">
            <div>
                <p class="eyebrow">Attendance <span class="dot-sep">·</span> {{ formatMonth(month) }}</p>
                <h1 class="display-title">{{ manager ? 'The floor, in focus' : 'Your hours, in focus' }}</h1>
                <p class="lede">A clear read on who worked, when, and how the month is shaping up.</p>
            </div>
            <div class="page-tools">
                <div class="month-switcher">
                    <Button icon="pi pi-chevron-left" text rounded aria-label="Previous month" @click="moveMonth(-1)" />
                    <button type="button" class="month-label" @click="thisMonth">{{ formatMonth(month) }}</button>
                    <Button icon="pi pi-chevron-right" text rounded aria-label="Next month" @click="moveMonth(1)" />
                </div>
                <Button v-if="manager" label="Export CSV" icon="pi pi-download" severity="secondary" outlined @click="download" />
            </div>
        </header>

        <div class="stat-strip">
            <div class="stat-card">
                <span class="stat-icon"><i class="pi pi-clock" /></span>
                <div><strong>{{ minutesLabel(totalMinutes) }}</strong><small>Total worked</small></div>
                <Tag v-if="openToday.length" :value="`${openToday.length} live`" severity="success" />
            </div>
            <div class="stat-card">
                <span class="stat-icon amber"><i class="pi pi-calendar" /></span>
                <div><strong>{{ daysWorked }}</strong><small>Days with hours</small></div>
            </div>
            <div class="stat-card">
                <span class="stat-icon blue"><i class="pi pi-chart-line" /></span>
                <div><strong>{{ minutesLabel(avgMinutes) }}</strong><small>Average day</small></div>
            </div>
            <div class="stat-card">
                <span class="stat-icon amber"><i class="pi pi-hourglass" /></span>
                <div><strong>{{ minutesLabel(overtimeMinutes) }}</strong><small>Overtime past 8h</small></div>
            </div>
            <div class="stat-card">
                <span class="stat-icon rose"><i class="pi pi-pause" /></span>
                <div><strong>{{ minutesLabel(breakMinutes) }}</strong><small>Break time</small></div>
            </div>
        </div>

        <Tabs v-model:value="tab" class="attendance-tabs">
            <TabList>
                <Tab value="overview"><i class="pi pi-chart-bar" /> Overview</Tab>
                <Tab value="ledger"><i class="pi pi-list" /> Ledger</Tab>
                <Tab value="team" v-if="manager"><i class="pi pi-users" /> Team</Tab>
            </TabList>

            <TabPanels>
                <TabPanel value="overview">
                    <div class="attendance-grid">
                        <section class="panel attendance-heat">
                            <header class="card-head">
                                <div>
                                    <p class="card-kicker">Pulse</p>
                                    <h2>{{ formatMonth(month) }} <span class="muted">· {{ scopeName }}</span></h2>
                                </div>
                                <Select
                                    v-if="manager"
                                    v-model="scope"
                                    :options="scopeOptions"
                                    option-label="name"
                                    option-value="id"
                                    class="scope-select"
                                >
                                    <template #value="slotProps">
                                        <div v-if="slotProps.value !== null" class="select-person compact">
                                            <span class="person-dot" :style="{ background: scopeOptions.find((person) => person.id === slotProps.value)?.color }" />
                                            <span>{{ scopeOptions.find((person) => person.id === slotProps.value)?.name }}</span>
                                        </div>
                                    </template>
                                    <template #option="slotProps">
                                        <div class="select-person compact">
                                            <span class="person-dot" :style="{ background: slotProps.option.color }" />
                                            <span>{{ slotProps.option.name }}</span>
                                        </div>
                                    </template>
                                </Select>
                            </header>

                            <div class="heatmap">
                                <span v-for="day in heatmapWeekdays" :key="day" class="heat-weekday">{{ day }}</span>
                                <template v-for="(cell, index) in heatmap" :key="cell?.date || `empty-${index}`">
                                    <span v-if="!cell" class="heat-cell empty" />
                                    <button
                                        v-else
                                        type="button"
                                        class="heat-cell"
                                        :class="[`level-${cell.level}`, { weekend: cell.weekend, today: cell.today }]"
                                        v-tooltip.top="cell.minutes ? `${formatFullDay(cell.date)} · ${minutesLabel(cell.minutes)}` : `${formatFullDay(cell.date)} · No hours`"
                                        @click="cell.row ? inspectRow(cell.row) : null"
                                    >
                                        <span>{{ cell.day }}</span>
                                        <i v-if="cell.row?.status === 'open' || cell.row?.status === 'on_break'" class="live-dot pulsing" />
                                    </button>
                                </template>
                            </div>

                            <div class="heat-legend">
                                <span>Less</span>
                                <i class="level-none" /><i class="level-low" /><i class="level-mid" /><i class="level-high" /><i class="level-deep" />
                                <span>More</span>
                                <span class="heat-note">{{ openToday.length ? `${openToday.length} shift${openToday.length === 1 ? '' : 's'} on the clock now` : 'No live shifts' }}</span>
                            </div>
                        </section>

                        <section class="panel today-summary">
                            <header class="card-head">
                                <div>
                                    <p class="card-kicker">Today</p>
                                    <h2>{{ formatFullDay() }}</h2>
                                </div>
                                <Tag :value="manager ? 'Team' : 'You'" severity="secondary" />
                            </header>
                            <div v-if="openToday.length" class="live-list">
                                <div v-for="row in openToday" :key="row.id" class="live-row">
                                    <span class="presence-avatar online">
                                        <PersonAvatar :person="office.members.find((person) => person.id === row.user_id)" :name="row.user_name" />
                                    </span>
                                    <span>
                                        <strong>{{ row.user_name }}</strong>
                                        <small>{{ row.status === 'on_break' ? 'On a break' : 'Working now' }} · in at {{ formatClock(row.check_in_at) }}</small>
                                    </span>
                                    <Tag :value="row.status === 'on_break' ? 'Break' : 'Live'" :severity="row.status === 'on_break' ? 'warn' : 'success'" />
                                </div>
                            </div>
                            <div v-else class="empty-mini">
                                <i class="pi pi-sun" />
                                <p>No one is on the clock right now.</p>
                            </div>
                            <Divider />
                            <div class="today-meta">
                                <div><span>Working days</span><strong>{{ office.workspace.work_days?.length || 5 }}</strong></div>
                                <div><span>Office hours</span><strong>{{ office.workspace.work_start }}–{{ office.workspace.work_end }}</strong></div>
                                <div><span>Timezone</span><strong>{{ office.workspace.timezone }}</strong></div>
                            </div>
                        </section>
                    </div>
                </TabPanel>

                <TabPanel value="ledger">
                    <section class="panel ledger-panel">
                        <header class="ledger-tools">
                            <IconField class="ledger-search">
                                <InputIcon class="pi pi-search" />
                                <InputText v-model="search" placeholder="Search person or date…" fluid />
                            </IconField>
                            <Select v-model="statusFilter" :options="statusOptions" option-label="label" option-value="value" />
                        </header>
                        <DataTable
                            :value="filteredRows"
                            :loading="loading"
                            paginator
                            :rows="10"
                            data-key="id"
                            striped-rows
                            removable-sort
                            scrollable
                            class="attendance-table"
                        >
                            <Column field="user_name" header="Person" sortable>
                                <template #body="{ data }">
                                    <div class="table-person">
                                        <PersonAvatar :person="office.members.find((person) => person.id === data.user_id)" :name="data.user_name" />
                                        <span><strong>{{ data.user_name }}</strong><small>{{ data.user_email }}</small></span>
                                    </div>
                                </template>
                            </Column>
                            <Column field="work_date" header="Date" sortable>
                                <template #body="{ data }">{{ formatDay(data.work_date) }}</template>
                            </Column>
                            <Column field="check_in_at" header="In">
                                <template #body="{ data }">{{ data.check_in_at ? formatClock(data.check_in_at) : '—' }}</template>
                            </Column>
                            <Column field="check_out_at" header="Out">
                                <template #body="{ data }">{{ data.check_out_at ? formatClock(data.check_out_at) : '—' }}</template>
                            </Column>
                            <Column field="counted_minutes" header="Worked" sortable>
                                <template #body="{ data }"><strong>{{ data.counted_label || data.work_label }}</strong></template>
                            </Column>
                            <Column field="overtime_minutes" header="Overtime" sortable>
                                <template #body="{ data }">
                                    <Tag v-if="data.overtime_minutes > 0" :value="data.overtime_label" severity="warn" />
                                    <span v-else>{{ data.overtime_label || '0m' }}</span>
                                </template>
                            </Column>
                            <Column field="break_minutes" header="Breaks">
                                <template #body="{ data }">{{ data.break_label }}</template>
                            </Column>
                            <Column field="status" header="Status">
                                <template #body="{ data }">
                                    <Tag :value="statusLabel(data.status)" :severity="statusSeverity(data.status)" />
                                </template>
                            </Column>
                            <Column header="">
                                <template #body="{ data }">
                                    <Button icon="pi pi-arrow-up-right" text rounded size="small" aria-label="Shift details" @click="inspectRow(data)" />
                                </template>
                            </Column>
                            <template #empty>
                                <div class="table-empty">
                                    <i class="pi pi-calendar" />
                                    <p>No shifts match this month or filter.</p>
                                </div>
                            </template>
                        </DataTable>
                    </section>
                </TabPanel>

                <TabPanel value="team" v-if="manager">
                    <div class="team-grid">
                        <button
                            v-for="person in teamSummary"
                            :key="person.id"
                            type="button"
                            class="team-card"
                            :class="{ selected: scope === person.id }"
                            @click="selectPerson(person.id)"
                        >
                            <span class="presence-avatar" :class="{ online: person.online }">
                                <PersonAvatar :person="person" :color="person.color" />
                            </span>
                            <span class="team-copy">
                                <strong>{{ person.name }}</strong>
                                <small>{{ person.title || 'Teammate' }}</small>
                            </span>
                            <span class="team-hours">
                                <strong>{{ minutesLabel(person.minutes) }}</strong>
                                <small>{{ person.days }} day{{ person.days === 1 ? '' : 's' }}<template v-if="person.overtime"> · {{ minutesLabel(person.overtime) }} overtime</template></small>
                            </span>
                            <Tag v-if="person.open" value="Live" severity="success" />
                        </button>
                        <div v-if="!teamSummary.length" class="empty-state small">
                            <i class="pi pi-users" />
                            <p>No team attendance in this month yet.</p>
                        </div>
                    </div>
                </TabPanel>
            </TabPanels>
        </Tabs>

        <Dialog v-model:visible="detailOpen" modal :style="{ width: '34rem' }" class="smack-dialog" @hide="selectedRow = null">
            <template #header>
                <div class="dialog-title">
                    <span class="dialog-icon"><i class="pi pi-stopwatch" /></span>
                    <div>
                        <strong>{{ selectedRow ? formatFullDay(selectedRow.work_date) : 'Shift' }}</strong>
                        <small>{{ selectedRow?.user_name }} · {{ selectedRow ? formatMonth(month) : '' }}</small>
                    </div>
                </div>
            </template>
            <div v-if="selectedRow" class="shift-detail">
                <div class="shift-detail-stats">
                    <div><span>Worked</span><strong>{{ selectedRow.counted_label || selectedRow.work_label }}</strong></div>
                    <div><span>Regular</span><strong>{{ selectedRow.regular_label || '0m' }}</strong></div>
                    <div><span>Overtime</span><strong>{{ selectedRow.overtime_label || '0m' }}</strong></div>
                    <div><span>Breaks</span><strong>{{ selectedRow.break_label }}</strong></div>
                </div>
                <div class="shift-detail-times">
                    <span><i class="pi pi-sign-in" /> {{ selectedRow.check_in_at ? formatClock(selectedRow.check_in_at) : 'Not checked in' }}</span>
                    <span><i class="pi pi-sign-out" /> {{ selectedRow.check_out_at ? formatClock(selectedRow.check_out_at) : 'Still open' }}</span>
                </div>
                <ol v-if="selectedRow.events?.length" class="event-timeline">
                    <li v-for="event in selectedRow.events" :key="`${event.type}-${event.happened_at}`">
                        <span class="timeline-dot" />
                        <span>{{ shiftLabel(event.type) }}</span>
                        <time>{{ formatClock(event.happened_at) }}</time>
                    </li>
                </ol>
                <Message v-if="notes(selectedRow)" severity="info" :closable="false">{{ notes(selectedRow) }}</Message>
            </div>
        </Dialog>
    </div>
</template>
