<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import api, { errorText } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useOfficeStore } from '@/stores/office';
import { dateFromTime, formatFullDay, isoDate, minutesLabel, timeFromDate, wallClockLabel } from '@/time';

const office = useOfficeStore();
const auth = useAuthStore();
const toast = useToast();
const confirm = useConfirm();

const days = [
    { label: 'Mon', value: 1 },
    { label: 'Tue', value: 2 },
    { label: 'Wed', value: 3 },
    { label: 'Thu', value: 4 },
    { label: 'Fri', value: 5 },
    { label: 'Sat', value: 6 },
    { label: 'Sun', value: 7 },
];
const zones = Intl.supportedValuesOf ? Intl.supportedValuesOf('timeZone') : ['UTC', 'Asia/Dubai', 'Europe/London', 'America/New_York'];

const schedule = ref(null);
const loading = ref(true);
const busy = ref(false);
const savingWorkspace = ref(false);
const holidayOpen = ref(false);
const startTime = ref(dateFromTime('09:00'));
const endTime = ref(dateFromTime('17:00'));
const breakAllowance = ref(0);
const breakOptions = [
    { label: 'No break', value: 0 },
    { label: '15 minutes', value: 15 },
    { label: '30 minutes', value: 30 },
    { label: '45 minutes', value: 45 },
    { label: '1 hour', value: 60 },
    { label: '1 hour 30 minutes', value: 90 },
    { label: '2 hours', value: 120 },
];
const form = reactive({ timezone: 'UTC', work_days: [1, 2, 3, 4, 5] });
const timesheetIds = ref([]);
const holiday = reactive({ name: '', observed_on: null, repeats_yearly: false });
const workspaceForm = reactive({ name: '', timezone: 'UTC' });
const locationForm = reactive({
    location_restricted: false,
    location_latitude: null,
    location_longitude: null,
    location_radius_m: 250,
    location_allowed_ips_text: '',
});
const locating = ref(false);
const viewerIp = ref('');

const today = computed(() => schedule.value?.today);
const windowMinutes = computed(() => {
    const start = timeFromDate(startTime.value);
    const end = timeFromDate(endTime.value);
    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);

    return Math.max(0, (eh * 60 + em) - (sh * 60 + sm));
});
const expectedWorkMinutes = computed(() => Math.max(0, windowMinutes.value - (breakAllowance.value || 0)));
const nextHoliday = computed(() => {
    if (!schedule.value?.holidays?.length) return null;
    const todayDate = new Date();
    todayDate.setHours(0, 0, 0, 0);

    return schedule.value.holidays
        .map((item) => {
            const date = new Date(`${item.observed_on}T12:00:00`);
            if (item.repeats_yearly && date < todayDate) {
                date.setFullYear(todayDate.getFullYear());
                if (date < todayDate) {
                    date.setFullYear(todayDate.getFullYear() + 1);
                }
            }

            return { ...item, date };
        })
        .filter((item) => item.date >= todayDate)
        .sort((a, b) => a.date - b.date)[0] || null;
});
const liveRows = computed(() => office.floorRows.filter((row) => row.status === 'open' || row.status === 'on_break'));
const canEdit = computed(() => office.manages);

onMounted(load);

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get(`/workspaces/${office.slug}/schedule`);
        applySchedule(data.schedule);
        workspaceForm.name = office.workspace.name;
        workspaceForm.timezone = office.workspace.timezone;
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Schedule', detail: errorText(error), life: 3500 });
    } finally {
        loading.value = false;
    }
}

function applySchedule(next) {
    schedule.value = next;
    form.timezone = next.timezone;
    form.work_days = [...next.work_days];
    startTime.value = dateFromTime(next.work_start);
    endTime.value = dateFromTime(next.work_end);
    breakAllowance.value = next.break_allowance_minutes ?? 0;
    timesheetIds.value = next.timesheet?.members.map((person) => person.id) || [];
    locationForm.location_restricted = !!next.location_restricted;
    locationForm.location_latitude = next.location_latitude ?? null;
    locationForm.location_longitude = next.location_longitude ?? null;
    locationForm.location_radius_m = next.location_radius_m ?? 250;
    locationForm.location_allowed_ips_text = (next.location_allowed_ips || []).join('\n');
    viewerIp.value = next.viewer_ip || '';
}

function allowedIpsList() {
    return locationForm.location_allowed_ips_text
        .split(/[\n,]+/)
        .map((entry) => entry.trim())
        .filter(Boolean);
}

async function save() {
    busy.value = true;
    try {
        const { data } = await api.put(`/workspaces/${office.slug}/schedule`, {
            timezone: form.timezone,
            work_start: timeFromDate(startTime.value),
            work_end: timeFromDate(endTime.value),
            break_allowance_minutes: breakAllowance.value,
            work_days: form.work_days,
            location_restricted: locationForm.location_restricted,
            location_latitude: locationForm.location_latitude,
            location_longitude: locationForm.location_longitude,
            location_radius_m: locationForm.location_radius_m,
            location_allowed_ips: allowedIpsList(),
        });
        applySchedule(data.schedule);

        if (data.schedule.timesheet) {
            await api.put(`/workspaces/${office.slug}/channels/${data.schedule.timesheet.slug}/members`, {
                member_ids: timesheetIds.value,
            });
        }

        await office.load(office.slug);
        toast.add({ severity: 'success', summary: 'Schedule saved', detail: 'The floor clock follows these hours.', life: 2600 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Schedule', detail: errorText(error), life: 3800 });
    } finally {
        busy.value = false;
    }
}

async function saveWorkspace() {
    savingWorkspace.value = true;
    try {
        await api.patch(`/workspaces/${office.slug}`, workspaceForm);
        await Promise.all([auth.fetchMe(), office.load(office.slug)]);
        toast.add({ severity: 'success', summary: 'Workspace updated', life: 2400 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Workspace', detail: errorText(error), life: 3500 });
    } finally {
        savingWorkspace.value = false;
    }
}

async function addHoliday() {
    try {
        await api.post(`/workspaces/${office.slug}/holidays`, {
            name: holiday.name,
            observed_on: holiday.observed_on ? isoDate(holiday.observed_on) : null,
            repeats_yearly: holiday.repeats_yearly,
        });
        holiday.name = '';
        holiday.observed_on = null;
        holiday.repeats_yearly = false;
        holidayOpen.value = false;
        await refreshSchedule();
        toast.add({ severity: 'success', summary: 'Holiday added', life: 2200 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Holiday', detail: errorText(error), life: 3200 });
    }
}

async function refreshSchedule() {
    const { data } = await api.get(`/workspaces/${office.slug}/schedule`);
    applySchedule(data.schedule);
}

function removeHoliday(item) {
    confirm.require({
        message: `Remove ${item.name} from the holiday calendar?`,
        header: 'Remove holiday',
        icon: 'pi pi-calendar-times',
        rejectProps: { label: 'Keep it', severity: 'secondary', text: true },
        acceptProps: { label: 'Remove', severity: 'danger' },
        accept: async () => {
            await api.delete(`/workspaces/${office.slug}/holidays/${item.id}`);
            await Promise.all([refreshSchedule(), office.refreshFloor()]);
            toast.add({ severity: 'success', summary: 'Holiday removed', life: 2000 });
        },
    });
}

function holidayDate(item) {
    return new Date(`${item.observed_on}T12:00:00`);
}

async function useMyLocation() {
    locating.value = true;
    try {
        const coords = await office.readLocation();
        locationForm.location_latitude = Number(coords.latitude.toFixed(7));
        locationForm.location_longitude = Number(coords.longitude.toFixed(7));
        toast.add({ severity: 'success', summary: 'Location captured', detail: 'Save the schedule to apply this checkpoint.', life: 2800 });
    } catch (error) {
        toast.add({ severity: 'warn', summary: 'Location', detail: error?.message || errorText(error), life: 3500 });
    } finally {
        locating.value = false;
    }
}

function useViewerIp() {
    if (!viewerIp.value) {
        toast.add({
            severity: 'warn',
            summary: 'Network',
            detail: 'Could not read this device’s public IP. Open Schedule from the office Wi‑Fi and try again.',
            life: 3500,
        });
        return;
    }

    const existing = allowedIpsList();
    if (!existing.includes(viewerIp.value)) {
        existing.push(viewerIp.value);
        locationForm.location_allowed_ips_text = existing.join('\n');
    }

    toast.add({
        severity: 'success',
        summary: 'Office IP added',
        detail: `${viewerIp.value} will be allowed after you save.`,
        life: 2800,
    });
}
</script>

<template>
    <div class="page schedule-page">
        <header class="page-head">
            <div>
                <p class="eyebrow">Schedule <span class="dot-sep">·</span> Floor clock</p>
                <h1 class="display-title">When the office is open</h1>
                <p class="lede">Set the working week, timezone, and holidays that decide how shifts are filed.</p>
            </div>
            <div class="page-tools">
                <Tag
                    :value="today?.office_open ? 'Office open today' : 'Closed today'"
                    :severity="today?.office_open ? 'success' : 'secondary'"
                    :icon="today?.office_open ? 'pi pi-check-circle' : 'pi pi-moon'"
                />
                <Button v-if="canEdit" label="Save schedule" icon="pi pi-check" :loading="busy" @click="save" />
            </div>
        </header>

        <section class="schedule-banner">
            <div class="schedule-banner-main">
                <span class="schedule-banner-icon"><i class="pi pi-sun" /></span>
                <div>
                    <p class="card-kicker">{{ formatFullDay() }}</p>
                    <h2>{{ today?.weekday || 'Today' }} <span class="muted">· {{ today?.office_open ? 'Open' : 'Closed' }}</span></h2>
                    <p>
                        <template v-if="today?.holiday">Holiday: {{ today.holiday }}.</template>
                        <template v-else-if="today?.office_open">The floor clock runs {{ wallClockLabel(schedule?.work_start) }}–{{ wallClockLabel(schedule?.work_end) }} in {{ schedule?.timezone }}.</template>
                        <template v-else>No scheduled hours today.</template>
                    </p>
                </div>
            </div>
            <div class="schedule-banner-stats">
                <div><span>Working days</span><strong>{{ form.work_days.length }}</strong></div>
                <div><span>Open window</span><strong>{{ wallClockLabel(schedule?.work_start) }}–{{ wallClockLabel(schedule?.work_end) }}</strong></div>
                <div><span>Live shifts</span><strong>{{ liveRows.length }}</strong></div>
            </div>
        </section>

        <div v-if="loading" class="schedule-grid">
            <Skeleton v-for="index in 4" :key="index" height="18rem" border-radius="24px" />
        </div>

        <div v-else class="schedule-grid">
            <section class="panel week-panel">
                <header class="card-head">
                    <div><p class="card-kicker">Working week</p><h2>Office hours</h2></div>
                    <Tag v-if="!canEdit" value="Read only" severity="secondary" />
                </header>

                <p class="form-label">Days the office is open</p>
                <SelectButton
                    v-model="form.work_days"
                    :options="days"
                    option-label="label"
                    option-value="value"
                    multiple
                    :disabled="!canEdit"
                    class="day-select"
                />

                <div class="time-pair">
                    <div class="field">
                        <label>Opens</label>
                        <DatePicker
                            v-model="startTime"
                            time-only
                            hour-format="24"
                            :show-icon="true"
                            icon-display="input"
                            :disabled="!canEdit"
                            fluid
                        />
                    </div>
                    <div class="field">
                        <label>Closes</label>
                        <DatePicker
                            v-model="endTime"
                            time-only
                            hour-format="24"
                            :show-icon="true"
                            icon-display="input"
                            :disabled="!canEdit"
                            fluid
                        />
                    </div>
                </div>

                <div class="field">
                    <label>Break allowance</label>
                    <Select
                        v-model="breakAllowance"
                        :options="breakOptions"
                        option-label="label"
                        option-value="value"
                        :disabled="!canEdit"
                        fluid
                    />
                    <small class="fine">Deducted from the open window for overtime. 9:00–18:00 with 1 hour break expects {{ minutesLabel(8 * 60) }}.</small>
                </div>

                <div class="field">
                    <label>Timezone</label>
                    <Select v-model="form.timezone" :options="zones" filter :disabled="!canEdit" fluid />
                </div>

                <div class="week-preview">
                    <div>
                        <span class="card-kicker">Preview</span>
                        <p>
                            {{ form.work_days.length }} day{{ form.work_days.length === 1 ? '' : 's' }} a week
                            · {{ wallClockLabel(timeFromDate(startTime)) }}–{{ wallClockLabel(timeFromDate(endTime)) }}
                            · {{ minutesLabel(expectedWorkMinutes) }} expected
                            <template v-if="breakAllowance"> after {{ minutesLabel(breakAllowance) }} break</template>
                        </p>
                    </div>
                    <span class="preview-days">
                        <i v-for="day in days" :key="day.value" :class="{ on: form.work_days.includes(day.value) }">{{ day.label.slice(0, 1) }}</i>
                    </span>
                </div>

                <p class="fine">Punched breaks still come off worked time. The allowance only sets the expected day used for overtime. Shifts outside this window are flagged but can still be filed.</p>
            </section>

            <section class="panel live-panel">
                <header class="card-head">
                    <div><p class="card-kicker">On the floor</p><h2>{{ liveRows.length ? `${liveRows.length} live now` : 'Nobody live' }}</h2></div>
                    <RouterLink :to="`/w/${office.slug}/attendance`" class="text-link">Attendance <i class="pi pi-arrow-right" /></RouterLink>
                </header>

                <div v-if="liveRows.length" class="live-list">
                    <div v-for="row in liveRows" :key="row.id" class="live-row">
                        <span class="presence-avatar online">
                            <PersonAvatar :person="office.members.find((person) => person.id === row.user_id)" :name="row.user_name" />
                        </span>
                        <span><strong>{{ row.user_name }}</strong><small>{{ row.status === 'on_break' ? 'On break' : 'Working' }} since {{ row.check_in_at ? new Date(row.check_in_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : 'today' }}</small></span>
                        <Tag :value="row.status === 'on_break' ? 'Break' : 'Live'" :severity="row.status === 'on_break' ? 'warn' : 'success'" />
                    </div>
                </div>
                <div v-else class="empty-mini">
                    <i class="pi pi-moon" />
                    <p>The floor is quiet. Live shifts will appear here.</p>
                </div>

                <Divider />
                <div class="next-holiday">
                    <span class="next-icon accent"><i class="pi pi-sun" /></span>
                    <div>
                        <strong>{{ nextHoliday ? nextHoliday.name : 'No holiday ahead' }}</strong>
                        <small>{{ nextHoliday ? formatFullDay(nextHoliday.date) : 'Add holidays to keep the calendar honest.' }}</small>
                    </div>
                </div>
            </section>

            <section class="panel holidays-panel">
                <header class="card-head">
                    <div><p class="card-kicker">Calendar</p><h2>Holidays</h2></div>
                    <Button v-if="canEdit" label="Add holiday" icon="pi pi-plus" size="small" severity="secondary" outlined @click="holidayOpen = true" />
                </header>

                <ul v-if="schedule?.holidays?.length" class="holiday-list">
                    <li v-for="item in schedule.holidays" :key="item.id">
                        <span class="holiday-date"><strong>{{ holidayDate(item).getDate() }}</strong><small>{{ holidayDate(item).toLocaleString(undefined, { month: 'short' }) }}</small></span>
                        <span class="holiday-copy">
                            <strong>{{ item.name }}</strong>
                            <small>{{ holidayDate(item).getFullYear() }}<template v-if="item.repeats_yearly"> · repeats yearly</template></small>
                        </span>
                        <Tag v-if="item.repeats_yearly" value="Yearly" severity="secondary" />
                        <Button v-if="canEdit" icon="pi pi-trash" text rounded size="small" severity="danger" aria-label="Remove holiday" @click="removeHoliday(item)" />
                    </li>
                </ul>
                <div v-else class="empty-mini">
                    <i class="pi pi-calendar" />
                    <p>No holidays on the calendar yet.</p>
                </div>
            </section>

            <section class="panel access-panel">
                <header class="card-head">
                    <div><p class="card-kicker">Privacy</p><h2>Timesheet readers</h2></div>
                    <Tag value="Private channel" severity="secondary" />
                </header>
                <p class="fine">The owner always sees checkout hours. Add the people who should read the private daily totals.</p>
                <MultiSelect
                    v-model="timesheetIds"
                    :options="office.members"
                    option-label="name"
                    option-value="id"
                    display="chip"
                    filter
                    :disabled="!canEdit"
                    placeholder="Add people"
                    fluid
                >
                    <template #option="slotProps">
                        <div class="select-person">
                            <PersonAvatar :person="slotProps.option" />
                            <span>{{ slotProps.option.name }}</span>
                        </div>
                    </template>
                </MultiSelect>
                <div class="access-people">
                    <span v-for="person in office.members.filter((member) => timesheetIds.includes(member.id))" :key="person.id" class="access-chip">
                        <PersonAvatar :person="person" size="small" />
                        {{ person.name }}
                    </span>
                </div>
            </section>

            <section class="panel location-panel">
                <header class="card-head">
                    <div><p class="card-kicker">Presence</p><h2>Office check-in guard</h2></div>
                    <Tag :value="locationForm.location_restricted ? 'On' : 'Off by default'" :severity="locationForm.location_restricted ? 'warn' : 'secondary'" />
                </header>
                <p class="fine">When on, check-in and checkout need both GPS inside the radius and the office public IP. Either alone is too easy to fake from home.</p>
                <div class="switch-row">
                    <div>
                        <strong>Require office presence</strong>
                        <small>GPS near the pin and connection from an approved office network IP.</small>
                    </div>
                    <ToggleSwitch v-model="locationForm.location_restricted" :disabled="!canEdit" />
                </div>
                <div class="form-grid two" :class="{ 'is-dimmed': !locationForm.location_restricted }">
                    <div class="field">
                        <label>Latitude</label>
                        <InputNumber
                            v-model="locationForm.location_latitude"
                            :min-fraction-digits="4"
                            :max-fraction-digits="7"
                            :min="-90"
                            :max="90"
                            :disabled="!canEdit || !locationForm.location_restricted"
                            fluid
                        />
                    </div>
                    <div class="field">
                        <label>Longitude</label>
                        <InputNumber
                            v-model="locationForm.location_longitude"
                            :min-fraction-digits="4"
                            :max-fraction-digits="7"
                            :min="-180"
                            :max="180"
                            :disabled="!canEdit || !locationForm.location_restricted"
                            fluid
                        />
                    </div>
                </div>
                <div class="field" :class="{ 'is-dimmed': !locationForm.location_restricted }">
                    <label>Allowed radius (meters)</label>
                    <InputNumber
                        v-model="locationForm.location_radius_m"
                        :min="25"
                        :max="100000"
                        suffix=" m"
                        :disabled="!canEdit || !locationForm.location_restricted"
                        fluid
                    />
                </div>
                <div class="field" :class="{ 'is-dimmed': !locationForm.location_restricted }">
                    <label>Approved office IPs</label>
                    <Textarea
                        v-model="locationForm.location_allowed_ips_text"
                        rows="4"
                        auto-resize
                        :disabled="!canEdit || !locationForm.location_restricted"
                        placeholder="One public IP or CIDR per line"
                        fluid
                    />
                    <div class="presence-help">
                        <p><strong>How to get the office IP</strong></p>
                        <ol>
                            <li>Connect this device to the <em>office Wi‑Fi</em> (not mobile data, not home Wi‑Fi).</li>
                            <li>Open this Schedule page here, then tap <strong>Add this device’s IP</strong>.</li>
                            <li>Save the schedule. That public IP is what SmackChat sees on check-in.</li>
                        </ol>
                        <p>
                            Prefer a whole office range? Ask IT for the office WAN/CIDR (example <code>203.0.113.0/24</code>) and paste one entry per line.
                        </p>
                        <p v-if="viewerIp" class="presence-help-current">
                            This device is currently seen as <code>{{ viewerIp }}</code>
                            <template v-if="viewerIp === '127.0.0.1' || viewerIp === '::1'">
                                — local testing only. On a live site this will be your real office public IP.
                            </template>
                        </p>
                        <p v-else class="presence-help-current">
                            Could not read this device’s IP yet. Refresh the page while on office Wi‑Fi.
                        </p>
                    </div>
                </div>
                <div class="dialog-actions" style="justify-content: flex-start; margin-top: 4px; gap: 8px;">
                    <Button
                        v-if="canEdit"
                        type="button"
                        label="Use my current location"
                        icon="pi pi-map-marker"
                        severity="secondary"
                        outlined
                        size="small"
                        :loading="locating"
                        :disabled="!locationForm.location_restricted"
                        @click="useMyLocation"
                    />
                    <Button
                        v-if="canEdit"
                        type="button"
                        label="Add this device’s IP"
                        icon="pi pi-wifi"
                        severity="secondary"
                        outlined
                        size="small"
                        :disabled="!locationForm.location_restricted || !viewerIp"
                        @click="useViewerIp"
                    />
                </div>
            </section>

            <section class="panel workspace-panel">
                <header class="card-head">
                    <div><p class="card-kicker">Workspace</p><h2>Identity</h2></div>
                </header>
                <template v-if="canEdit">
                    <div class="field">
                        <label>Workspace name</label>
                        <InputText v-model="workspaceForm.name" fluid />
                    </div>
                    <div class="field">
                        <label>Workspace timezone</label>
                        <Select v-model="workspaceForm.timezone" :options="zones" filter fluid />
                    </div>
                    <Button label="Save workspace" icon="pi pi-check" severity="secondary" outlined :loading="savingWorkspace" @click="saveWorkspace" />
                </template>
                <div v-else class="workspace-readonly">
                    <strong>{{ office.workspace.name }}</strong>
                    <span>{{ office.workspace.timezone }}</span>
                </div>
                <Divider />
                <div class="workspace-meta">
                    <span>{{ office.workspace.seats }} seats · {{ office.workspace.timezone }}</span>
                    <Tag :value="office.workspace.plan === 'archive' ? 'Forever archive' : `${office.workspace.retention_days}-day history`" :severity="office.workspace.plan === 'archive' ? 'success' : 'secondary'" />
                </div>
            </section>
        </div>

        <Dialog v-model:visible="holidayOpen" modal :style="{ width: '28rem' }" class="smack-dialog">
            <template #header>
                <div class="dialog-title">
                    <span class="dialog-icon"><i class="pi pi-sun" /></span>
                    <div><strong>Add a holiday</strong><small>The floor clock will flag shifts on this date.</small></div>
                </div>
            </template>
            <form class="form-stack" @submit.prevent="addHoliday">
                <FloatLabel variant="on">
                    <InputText id="holiday-name" v-model="holiday.name" fluid />
                    <label for="holiday-name">Holiday name</label>
                </FloatLabel>
                <div class="field">
                    <label>Date</label>
                    <DatePicker v-model="holiday.observed_on" date-format="yy-mm-dd" show-icon icon-display="input" fluid />
                </div>
                <div class="switch-row">
                    <div><strong>Repeats every year</strong><small>Useful for fixed public holidays.</small></div>
                    <ToggleSwitch v-model="holiday.repeats_yearly" />
                </div>
                <div class="dialog-actions">
                    <Button label="Cancel" text severity="secondary" type="button" @click="holidayOpen = false" />
                    <Button type="submit" label="Add holiday" icon="pi pi-plus" :disabled="!holiday.name || !holiday.observed_on" />
                </div>
            </form>
        </Dialog>
    </div>
</template>
