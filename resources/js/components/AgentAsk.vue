<script setup>
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from '@/toast';
import api, { errorText } from '@/api';
import {
    agentPlanNeedsModel,
    applyToneHint,
    decideAgentSteps,
    describeAgentPlan,
    replyBrief,
    resolveOpenTarget,
    routeAgentSteps,
    saveAgentFollowup,
    shiftPunchAllowed,
    shiftRefusal,
    shiftStatusText,
} from '@/agent';
import {
    ai,
    initAiCapabilities,
    isDownloadRequired,
    planAgentActionLocally,
    prepareModel,
    rewriteDraftLocally,
    summarizeMessagesLocally,
    writeDraftLocally,
} from '@/ai';
import { searchMessages } from '@/e2e';
import { useAuthStore } from '@/stores/auth';
import { useOfficeStore } from '@/stores/office';

const props = defineProps({
    messages: { type: Array, default: () => [] },
    channelSlug: { type: String, default: '' },
    heading: { type: Boolean, default: true },
    getDraft: { type: Function, default: null },
    setDraft: { type: Function, default: null },
    initialSteps: { type: Array, default: () => [] },
});

const busy = defineModel('busy', { type: Boolean, default: false });
const phase = defineModel('phase', { type: String, default: '' });

const emit = defineEmits(['close', 'revert', 'deliver', 'started']);

const router = useRouter();
const toast = useToast();
const auth = useAuthStore();
const office = useOfficeStore();

const field = ref(null);
const request = ref('');

function focus() {
    const input = field.value?.$el || field.value;
    (input?.querySelector?.('textarea') || input)?.focus?.();
}

defineExpose({ focus });
const tone = ref('neutral');
const outcome = ref(null);
const downloadConfirm = ref(false);
const downloadBusy = ref(false);
const pendingRun = ref(null);
let runToken = 0;

const toneOptions = [
    { label: 'Neutral', value: 'neutral' },
    { label: 'Professional', value: 'formal' },
    { label: 'Casual', value: 'casual' },
];

const suggestions = [
    { label: 'Summarize', text: 'Summarize this conversation' },
    { label: 'Unread', text: 'What is unread?' },
    { label: 'My shift', text: 'Am I checked in?' },
    { label: 'Break', text: 'break' },
    { label: 'Reply', text: 'Draft a reply' },
];

const canRun = computed(() => request.value.trim().length >= 2 && !busy.value);

const pages = computed(() => ({
    today: `/w/${office.slug}`,
    attendance: `/w/${office.slug}/attendance`,
    schedule: `/w/${office.slug}/hours`,
    people: `/w/${office.slug}/members`,
    settings: `/w/${office.slug}/settings`,
    billing: `/w/${office.slug}/billing`,
}));

function context() {
    const channel = office.channels.find((item) => item.slug === props.channelSlug);

    return {
        channelLabel: channel?.label || '',
        channelSlug: channel?.slug || '',
        hasDraft: Boolean(props.getDraft?.().trim()),
        hasMessages: props.messages.some((message) => String(message?.text || '').trim()),
        shift: office.today?.status || 'idle',
        channels: office.channels.map((item) => ({ slug: item.slug, label: item.label, type: item.type })),
        people: office.members
            .filter((person) => person.id !== auth.user?.id)
            .map((person) => ({ id: person.id, name: person.name })),
    };
}

function explainUnavailable() {
    toast.add({
        severity: 'info',
        summary: 'On-device AI',
        detail: 'This browser cannot run the on-device model. Nothing is sent to the server.',
        life: 4200,
    });
}

async function ensureModel(action) {
    try {
        await initAiCapabilities();
    } catch {
        explainUnavailable();
        return false;
    }

    if (ai.engine === 'unavailable') {
        explainUnavailable();
        return false;
    }

    if (ai.modelState === 'ready') {
        return true;
    }

    if (ai.engine === 'gemini-nano') {
        try {
            await prepareModel();
            return ai.modelState === 'ready';
        } catch (error) {
            if (!isDownloadRequired(error)) {
                toast.add({
                    severity: 'warn',
                    summary: 'On-device AI',
                    detail: errorText(error, 'Could not set up on-device AI.'),
                    life: 3800,
                });
                return false;
            }
        }
    }

    pendingRun.value = action;
    downloadConfirm.value = true;
    return false;
}

async function confirmDownload() {
    if (downloadBusy.value) {
        return;
    }

    downloadBusy.value = true;
    const stop = toast.followPercent('ai-model', 'Downloading model', () => ai.downloadPercent);

    try {
        await prepareModel();
        stop();
        toast.finish('ai-model', {
            summary: 'Offline AI ready',
            detail: 'The model is cached on this device.',
            life: 2800,
        });
        downloadConfirm.value = false;
        const action = pendingRun.value;
        pendingRun.value = null;

        if (ai.modelState === 'ready' && action) {
            await action();
        }
    } catch (error) {
        stop();
        toast.finish('ai-model', {
            severity: 'warn',
            summary: 'Offline AI',
            detail: errorText(error, 'Could not start the offline AI model on this device.'),
            life: 4600,
        });
    } finally {
        downloadBusy.value = false;
    }
}

function cancelDownload() {
    pendingRun.value = null;
    downloadConfirm.value = false;
}

function useSuggestion(text) {
    request.value = text;
    run();
}

async function run() {
    const brief = request.value.trim();

    if (brief.length < 2 || busy.value) {
        return;
    }

    if (agentPlanNeedsModel(routeAgentSteps(brief, context())) && !(await ensureModel(() => perform(brief)))) {
        return;
    }

    await perform(brief);
}

async function perform(brief) {
    const token = ++runToken;
    busy.value = true;
    phase.value = 'Choosing an action';
    outcome.value = null;
    let drafted = false;

    try {
        const steps = (await decideAgentSteps(
            brief,
            context(),
            (text, agentContext) => planAgentActionLocally(text, agentContext),
        )).map((step) => applyToneHint(step, tone.value));

        if (token !== runToken) {
            return;
        }

        await executeSteps(steps, token, () => {
            drafted = true;
        });
    } catch (error) {
        if (token !== runToken) {
            return;
        }

        if (drafted) {
            emit('revert');
        }

        toast.add({
            severity: 'warn',
            summary: 'On-device AI',
            detail: errorText(error, 'Could not run that on this device.'),
            life: 3800,
        });
    } finally {
        if (token === runToken) {
            busy.value = false;
            phase.value = '';
        }
    }
}

async function executeSteps(steps, token, markDrafted) {
    for (let index = 0; index < steps.length; index += 1) {
        if (token !== runToken) {
            return;
        }

        const plan = steps[index];
        const rest = steps.slice(index + 1);
        phase.value = describeAgentPlan(plan);

        if (plan.action === 'none') {
            outcome.value = { kicker: 'On-device AI', body: plan.say || 'I could not match that to an action.' };
            return;
        }

        if (plan.action === 'open' && rest.length) {
            const target = resolveOpenTarget(plan.target, context(), plan.intent || 'any');

            if (target?.type === 'channel' || target?.type === 'person') {
                await handoff(plan, rest);
                return;
            }
        }

        if ((plan.action === 'draft' || plan.action === 'reply') && !props.setDraft) {
            await handoff(plan, [plan, ...rest]);
            return;
        }

        await execute(plan, token, markDrafted);
    }
}

function postingChannel(plan) {
    if (plan?.target) {
        const resolved = resolveOpenTarget(plan.target, context(), plan.intent || 'channel');

        if (resolved?.type === 'channel') {
            return resolved;
        }
    }

    if (props.channelSlug) {
        const current = office.channels.find((item) => item.slug === props.channelSlug);

        if (current) {
            return { type: 'channel', slug: current.slug, label: current.label };
        }
    }

    const general = office.channels.find((item) => item.slug === 'general')
        || office.channels.find((item) => item.type === 'public');

    return general ? { type: 'channel', slug: general.slug, label: general.label } : null;
}

async function handoff(plan, steps) {
    const target = plan.action === 'open'
        ? resolveOpenTarget(plan.target, context(), plan.intent || 'any')
        : postingChannel(plan);

    if (!target || (target.type !== 'channel' && target.type !== 'person')) {
        outcome.value = { kicker: 'Open', body: 'Nothing is open to finish that in.' };
        return;
    }

    let slug = target.slug;

    if (target.type === 'person') {
        const { data } = await api.post(`/workspaces/${office.slug}/dms`, { user_id: target.id });
        slug = data.slug;
    }

    saveAgentFollowup(steps.map((step) => ({ ...step, channelSlug: slug })));
    await router.push(`/w/${office.slug}/c/${slug}`);
    request.value = '';
    emit('close');
}

watch(() => props.initialSteps, (steps) => {
    if (!steps?.length || busy.value) {
        return;
    }

    const pending = steps.slice(0, 3);
    emit('started');
    const token = ++runToken;
    performGiven(pending, token);
}, { deep: true, immediate: true });

async function performGiven(steps, token) {
    if (agentPlanNeedsModel(steps) && !(await ensureModel(() => performGiven(steps, token)))) {
        return;
    }

    busy.value = true;
    let drafted = false;

    try {
        await executeSteps(steps, token, () => {
            drafted = true;
        });
    } catch (error) {
        if (drafted) {
            emit('revert');
        }

        toast.add({
            severity: 'warn',
            summary: 'On-device AI',
            detail: errorText(error, 'Could not run that on this device.'),
            life: 3800,
        });
    } finally {
        if (token === runToken) {
            busy.value = false;
            phase.value = '';
        }
    }
}

async function execute(plan, token, markDrafted) {
    if (plan.action === 'draft' || plan.action === 'reply') {
        const instruction = plan.action === 'reply'
            ? replyBrief(plan.brief, props.messages)
            : plan.brief;
        markDrafted();
        const result = await writeDraftLocally(instruction, { tone: plan.tone || 'neutral', length: 'short' }, {
            onChunk: (chunk) => {
                if (token === runToken && props.setDraft) {
                    props.setDraft(chunk);
                }
            },
        });

        if (token !== runToken) {
            return;
        }

        const text = result || instruction;

        if (props.setDraft) {
            props.setDraft(text);
            request.value = '';

            if (text.trim() && plan.deliver !== false) {
                emit('deliver');
                toast.add({ severity: 'success', summary: 'Sent', life: 1800 });
            }

            emit('close');
            return;
        }

        await handoff(plan, [plan]);
        return;
    }

    if (plan.action === 'rewrite') {
        const current = props.getDraft?.() || '';

        if (!current.trim() || !props.setDraft) {
            outcome.value = { kicker: 'Rewrite', body: 'Write a draft in the composer first.' };
            return;
        }

        markDrafted();
        const result = await rewriteDraftLocally(current, {
            tone: plan.tone === 'formal' ? 'more-formal' : plan.tone === 'casual' ? 'more-casual' : 'as-is',
            length: plan.length === 'shorter' ? 'shorter' : 'as-is',
            action: plan.mode === 'fix-grammar' ? 'fix-grammar' : 'rewrite',
        }, {
            onChunk: (chunk) => {
                if (token === runToken) {
                    props.setDraft(chunk);
                }
            },
        });

        if (token !== runToken) {
            return;
        }

        const polished = result || current;
        props.setDraft(polished);
        request.value = '';

        if (polished.trim() && plan.deliver) {
            emit('deliver');
            toast.add({ severity: 'success', summary: 'Sent', life: 1800 });
        }

        emit('close');
        return;
    }

    if (plan.action === 'summarize') {
        const summary = await summarizeMessagesLocally(props.messages.map((message) => ({
            text: message.text,
            user: { name: message.name },
        })));

        if (token !== runToken) {
            return;
        }

        outcome.value = {
            kicker: 'Summary',
            body: summary || 'There is nothing to summarize yet.',
            note: 'Generated on this device.',
        };
        return;
    }

    if (plan.action === 'search') {
        const found = await searchMessages(
            office,
            plan.query,
            plan.scope === 'channel' ? props.channelSlug || null : null,
        );

        if (token !== runToken) {
            return;
        }

        outcome.value = {
            kicker: 'Search',
            body: found.length ? '' : `No decrypted messages matched “${plan.query}”.`,
            note: 'The search phrase stayed on this device.',
            items: found.slice(0, 6).map((result) => ({
                label: result.snippet,
                meta: `${result.channel.label} · ${result.message.user?.name || 'Someone'}`,
                command: () => openMessage(result),
            })),
        };
        return;
    }

    if (plan.action === 'open') {
        const target = resolveOpenTarget(plan.target, context(), plan.intent || 'any');

        if (!target) {
            outcome.value = { kicker: 'Open', body: `Nothing matched “${plan.target}”.` };
            return;
        }

        if (target.type === 'page') {
            await router.push(pages.value[target.id]);
        } else if (target.type === 'channel') {
            await router.push(`/w/${office.slug}/c/${target.slug}`);
        } else {
            await startDm(target.id);
        }

        request.value = '';
        emit('close');
        return;
    }

    if (plan.action === 'unread') {
        const rows = office.catchUp;
        outcome.value = {
            kicker: 'Unread',
            body: rows.length ? '' : 'You are caught up.',
            items: rows.slice(0, 8).map((channel) => ({
                label: channel.label,
                meta: `${channel.unread} unread`,
                command: () => {
                    router.push(`/w/${office.slug}/c/${channel.slug}`);
                    emit('close');
                },
            })),
        };
        return;
    }

    if (plan.action === 'shift') {
        await runShift(plan.punch || 'status');
        return;
    }

    outcome.value = { kicker: 'On-device AI', body: plan.say || 'I could not match that to an action.' };
}

async function runShift(punch) {
    const status = shiftStatusText(office.today);

    if (punch === 'status') {
        outcome.value = { kicker: 'Shift', body: status };
        toast.add({ severity: 'info', summary: 'Shift', detail: status, life: 2800 });
        return;
    }

    if (punch === 'break_in' && shiftPunchAllowed('check_in', office.today)) {
        await office.punch('check_in');
    }

    if (punch === 'break_in' && office.today?.status === 'on_break') {
        const body = 'You are already on a break.';
        outcome.value = { kicker: 'Shift', body };
        toast.add({ severity: 'info', summary: 'Break', detail: body, life: 2800 });
        return;
    }

    if (!shiftPunchAllowed(punch, office.today)) {
        const body = shiftRefusal(punch, office.today);
        outcome.value = { kicker: 'Shift', body };
        toast.add({ severity: 'warn', summary: 'Attendance', detail: body, life: 3500 });
        return;
    }

    const result = await office.punch(punch);
    const detail = punch === 'check_in'
        ? 'Your day has started.'
        : punch === 'break_in'
            ? 'Your break has started.'
            : punch === 'break_out'
                ? 'You are back to work.'
                : `${result.day?.work_label || 'The shift'} landed in Timesheets.`;
    outcome.value = { kicker: 'Shift', body: detail };
    toast.add({
        severity: 'success',
        summary: punch === 'break_in' ? 'On a break' : punch === 'break_out' ? 'Back to work' : punch === 'check_in' ? 'On the clock' : 'Shift filed',
        detail,
        life: 3200,
        confetti: punch === 'check_in',
    });
}

function openMessage(result) {
    const message = result.message;
    const parent = message.parent_ref || message.ref;
    const reply = message.parent_ref ? message.ref : null;
    router.push({
        path: `/w/${office.slug}/c/${result.channel.slug}`,
        query: parent ? { m: parent, ...(reply ? { reply } : {}) } : {},
    });
    emit('close');
}

async function startDm(userId) {
    const { data } = await api.post(`/workspaces/${office.slug}/dms`, { user_id: userId });
    await router.push(`/w/${office.slug}/c/${data.slug}`);
}
</script>

<template>
    <form class="composer-write-form agent-ask" @submit.prevent="run">
        <template v-if="heading">
            <p class="form-label">Ask on-device AI</p>
            <p class="composer-write-copy">It runs here, then sends the message or updates your clock. No extra confirm.</p>
        </template>
        <Textarea
            ref="field"
            v-model="request"
            rows="3"
            auto-resize
            fluid
            maxlength="1000"
            placeholder="Summarize this, search for the demo, open #general, or describe a message"
            :disabled="busy"
            class="composer-write-input"
        />
        <div class="composer-write-tones">
            <button
                v-for="item in suggestions"
                :key="item.label"
                type="button"
                class="composer-write-tone"
                :disabled="busy"
                @click="useSuggestion(item.text)"
            >
                {{ item.label }}
            </button>
        </div>
        <div class="composer-write-tones">
            <button
                v-for="option in toneOptions"
                :key="option.value"
                type="button"
                class="composer-write-tone"
                :class="{ active: tone === option.value }"
                :disabled="busy"
                @click="tone = option.value"
            >
                {{ option.label }}
            </button>
        </div>
        <div class="link-actions">
            <Button label="Cancel" text severity="secondary" size="small" type="button" :disabled="busy" @click="emit('close')" />
            <Button
                label="Run"
                icon="pi pi-sparkles"
                size="small"
                type="submit"
                :disabled="!canRun"
                :loading="busy"
            />
        </div>

        <section v-if="outcome" class="agent-outcome">
            <p class="card-kicker">{{ outcome.kicker }}</p>
            <p v-if="outcome.body">{{ outcome.body }}</p>
            <ul v-if="outcome.items?.length" class="agent-outcome-list">
                <li v-for="(item, index) in outcome.items" :key="index">
                    <button type="button" @click="item.command">
                        <strong>{{ item.label }}</strong>
                        <small>{{ item.meta }}</small>
                    </button>
                </li>
            </ul>
            <p v-if="outcome.note" class="agent-outcome-note">{{ outcome.note }}</p>
        </section>
    </form>

    <Dialog v-model:visible="downloadConfirm" modal :style="{ width: '28rem' }" class="smack-dialog">
        <template #header>
            <div class="dialog-title">
                <span class="dialog-icon"><i class="pi pi-download" /></span>
                <div><strong>Download Offline AI</strong><small>One-time setup · stays on this device</small></div>
            </div>
        </template>
        <div class="ai-download-dialog">
            <p>
                Download Offline AI ({{ ai.modelSizeHint }})? It runs rewrite, draft, summary, and these
                actions on this device. If the full model cannot run here, a smaller one is used automatically.
            </p>
            <div v-if="ai.modelState === 'downloading'" class="ai-download-row">
                <div class="ai-download-copy">
                    <i class="pi pi-cloud-download" />
                    <span>{{ ai.downloadLabel }}</span>
                </div>
                <ProgressBar :value="ai.downloadPercent" :show-value="false" class="ai-download-bar" />
            </div>
            <div class="link-actions">
                <Button label="Not now" text severity="secondary" size="small" :disabled="downloadBusy" @click="cancelDownload" />
                <Button
                    label="Download Once & Enable"
                    icon="pi pi-download"
                    size="small"
                    :loading="downloadBusy"
                    :disabled="ai.modelState === 'downloading'"
                    @click="confirmDownload"
                />
            </div>
        </div>
    </Dialog>
</template>
