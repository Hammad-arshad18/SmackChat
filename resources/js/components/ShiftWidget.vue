<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import { errorText } from '@/api';
import { useOfficeStore } from '@/stores/office';
import { formatClock, minutesLabel, shiftTotals, durationClock, wallClockLabel } from '@/time';

const props = defineProps({
    variant: { type: String, default: 'compact' },
});

const office = useOfficeStore();
const toast = useToast();
const tick = ref(Date.now());
const busy = ref(false);
let timer;

const status = computed(() => office.today?.status || 'idle');
const totals = computed(() => shiftTotals(office.today?.events || [], tick.value));
const scheduledMinutes = computed(() => {
    const start = office.workspace?.work_start || '09:00';
    const end = office.workspace?.work_end || '17:00';
    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);

    return Math.max(60, (eh * 60 + em) - (sh * 60 + sm));
});
const workedMinutes = computed(() => {
    if (status.value === 'completed') {
        return office.today?.work_minutes || 0;
    }

    return Math.floor(totals.value.workSeconds / 60);
});
const progress = computed(() => Math.min(100, Math.round((workedMinutes.value / scheduledMinutes.value) * 100)));
const stateLabel = computed(() => ({
    idle: 'Not checked in',
    open: 'On the clock',
    on_break: 'On a break',
    completed: 'Day complete',
}[status.value] || 'Not checked in'));
const stateDetail = computed(() => {
    if (status.value === 'idle') {
        return office.workspace ? `Office hours ${wallClockLabel(office.workspace.work_start)}–${wallClockLabel(office.workspace.work_end)}` : '';
    }

    if (status.value === 'completed') {
        return `${office.today?.work_label || '0m'} worked · ${office.today?.break_label || '0m'} break`;
    }

    if (status.value === 'on_break') {
        return `${durationClock(totals.value.breakSeconds)} on break`;
    }

    return `${durationClock(totals.value.workSeconds)} today`;
});
const note = computed(() => {
    if (office.today?.holiday_name) return `Holiday · ${office.today.holiday_name}`;
    if (office.today?.non_working_day) return 'Outside the working week';
    if (office.today?.outside_hours) return 'Outside scheduled hours';
    if (office.workspace?.location_restricted) return 'Location check required for check-in and checkout';
    return '';
});
const eventLabel = computed(() => ({
    check_in: 'Checked in',
    break_in: 'Break started',
    break_out: 'Back to work',
    check_out: 'Checked out',
}));
const events = computed(() => (office.today?.events || []).slice().sort((a, b) => new Date(a.happened_at) - new Date(b.happened_at)));

onMounted(() => {
    timer = setInterval(() => { tick.value = Date.now(); }, 1000);
});

onBeforeUnmount(() => clearInterval(timer));

async function punch(action) {
    busy.value = true;
    try {
        const result = await office.punch(action);
        if (action === 'check_out') {
            toast.add({
                severity: 'success',
                summary: 'Shift filed',
                detail: `${result.day.work_label} of work landed in Timesheets.`,
                life: 4000,
            });
        } else if (action === 'check_in') {
            toast.add({ severity: 'success', summary: 'You are on the clock', detail: 'The floor clock is running.', life: 3000 });
        }
    } catch (error) {
        toast.add({ severity: 'warn', summary: 'Attendance', detail: errorText(error), life: 4000 });
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="shift-widget" :class="[`shift-${variant}`, `shift-state-${status}`]">
        <div v-if="variant === 'hero'" class="shift-glow" />
        <header class="shift-head">
            <div>
                <p class="shift-kicker">
                    <span class="live-dot" :class="{ pulsing: status === 'open' || status === 'on_break' }" />
                    {{ stateLabel }}
                </p>
                <h2 v-if="variant === 'hero'" class="shift-time">
                    {{ status === 'completed' ? office.today?.work_label : durationClock(totals.workSeconds) }}
                </h2>
                <p class="shift-detail">{{ stateDetail }}</p>
            </div>
            <Knob
                v-if="variant === 'hero'"
                :model-value="progress"
                :size="112"
                :stroke-width="9"
                readonly
                :value-template="() => `${progress}%`"
                class="shift-knob"
            />
        </header>

        <div v-if="variant === 'hero'" class="shift-progress">
            <div class="shift-progress-labels">
                <span>Shift progress</span>
                <span>{{ minutesLabel(workedMinutes) }} / {{ minutesLabel(scheduledMinutes) }}</span>
            </div>
            <ProgressBar :value="progress" :show-value="false" class="shift-bar" />
        </div>

        <p v-if="note" class="shift-note"><i class="pi pi-info-circle" /> {{ note }}</p>

        <div class="shift-actions">
            <Button
                v-if="!office.today || status === 'idle'"
                label="Check in"
                icon="pi pi-sign-in"
                :loading="busy"
                size="small"
                @click="punch('check_in')"
            />
            <Button
                v-if="status === 'open'"
                label="Break"
                icon="pi pi-pause"
                severity="secondary"
                size="small"
                :loading="busy"
                @click="punch('break_in')"
            />
            <Button
                v-if="status === 'on_break'"
                label="Back to work"
                icon="pi pi-play"
                size="small"
                :loading="busy"
                @click="punch('break_out')"
            />
            <Button
                v-if="office.today && status !== 'completed'"
                label="Check out"
                icon="pi pi-sign-out"
                severity="secondary"
                outlined
                size="small"
                class="shift-checkout"
                :loading="busy"
                @click="punch('check_out')"
            />
            <Tag v-if="status === 'completed'" severity="success" value="Filed" icon="pi pi-check" />
        </div>

        <ol v-if="variant === 'hero' && events.length" class="shift-timeline">
            <li v-for="event in events" :key="`${event.type}-${event.happened_at}`">
                <span class="timeline-dot" :class="`dot-${event.type}`" />
                <span>{{ eventLabel[event.type] }}</span>
                <time>{{ formatClock(event.happened_at) }}</time>
            </li>
        </ol>
    </section>
</template>
