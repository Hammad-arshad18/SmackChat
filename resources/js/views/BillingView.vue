<script setup>
import { computed, ref } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import api, { errorText } from '@/api';
import { useOfficeStore } from '@/stores/office';
import { formatFullDay, money } from '@/time';

const office = useOfficeStore();
const toast = useToast();
const confirm = useConfirm();
const busy = ref(false);

const archivePrice = computed(() => money(office.workspace.archive_price_cents));
const monthly = computed(() => money(office.workspace.monthly_cents));
const isArchive = computed(() => office.workspace.plan === 'archive');
const standardFeatures = computed(() => [
    `${office.workspace.retention_days} days of message history`,
    'Channels, threads, and direct messages',
    'The floor clock and daily timesheets',
    'Attendance ledger and CSV export',
    'People directory and invitations',
]);
const archiveFeatures = computed(() => [
    'Everything in Standard',
    'Every message kept forever',
    'Checkout posts never age out',
    'Full attendance history',
    'Priority placement for history requests',
]);

function chooseArchive() {
    if (!office.isOwner) {
        return;
    }

    confirm.require({
        message: `Keep every message forever for ${office.workspace.seats} people at ${monthly.value} per month?`,
        header: 'Turn on forever archive',
        icon: 'pi pi-sparkles',
        rejectProps: { label: 'Not now', severity: 'secondary', text: true },
        acceptProps: { label: 'Turn on archive', severity: 'success' },
        accept: archive,
    });
}

function chooseStandard() {
    if (!office.isOwner) {
        return;
    }

    confirm.require({
        message: `Return to the standard ${office.workspace.retention_days}-day window? Messages older than that will be pruned nightly.`,
        header: 'Return to standard',
        icon: 'pi pi-history',
        rejectProps: { label: 'Keep archive', severity: 'secondary', text: true },
        acceptProps: { label: 'Return to standard', severity: 'warn' },
        accept: standard,
    });
}

async function archive() {
    busy.value = true;
    try {
        const { data } = await api.post(`/workspaces/${office.slug}/billing/archive`);
        await office.load(office.slug);
        toast.add({ severity: 'success', summary: 'Forever archive is on', detail: data.message, life: 4500 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Billing', detail: errorText(error), life: 3500 });
    } finally {
        busy.value = false;
    }
}

async function standard() {
    busy.value = true;
    try {
        const { data } = await api.post(`/workspaces/${office.slug}/billing/standard`);
        await office.load(office.slug);
        toast.add({ severity: 'contrast', summary: 'Plan updated', detail: data.message, life: 3800 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Billing', detail: errorText(error), life: 3500 });
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="page billing-page">
        <header class="page-head">
            <div>
                <p class="eyebrow">Plans <span class="dot-sep">·</span> {{ office.workspace.name }}</p>
                <h1 class="display-title">Keep the record, or let it age out</h1>
                <p class="lede">Choose how long your workspace remembers. Checkout posts, threads, and messages all follow the same clock.</p>
            </div>
            <div class="page-tools">
                <Tag
                    :value="isArchive ? 'Forever archive' : 'Standard plan'"
                    :severity="isArchive ? 'success' : 'secondary'"
                    :icon="isArchive ? 'pi pi-verified' : 'pi pi-history'"
                />
            </div>
        </header>

        <section class="billing-hero">
            <div class="billing-hero-copy">
                <span class="billing-hero-icon"><i :class="isArchive ? 'pi pi-verified' : 'pi pi-history'" /></span>
                <div>
                    <p class="card-kicker">Current plan</p>
                    <h2>{{ isArchive ? 'Forever archive' : 'Standard' }}</h2>
                    <p v-if="isArchive">Every message in {{ office.workspace.name }} is kept forever.</p>
                    <p v-else>Messages stay for {{ office.workspace.retention_days }} days, then are pruned each night.</p>
                </div>
            </div>
            <div class="billing-hero-stats">
                <div><span>Seats</span><strong>{{ office.workspace.seats }}</strong></div>
                <div><span>Monthly</span><strong>{{ isArchive ? monthly : 'Included' }}</strong></div>
                <div><span>Since</span><strong>{{ office.workspace.plan_activated_at ? formatFullDay(office.workspace.plan_activated_at) : 'Always' }}</strong></div>
            </div>
        </section>

        <div class="plan-grid">
            <article class="plan-card" :class="{ current: !isArchive }">
                <div class="plan-ribbon" v-if="!isArchive">Current plan</div>
                <div class="plan-head">
                    <span class="plan-icon"><i class="pi pi-history" /></span>
                    <div>
                        <p class="card-kicker">Standard</p>
                        <h2>Included</h2>
                    </div>
                </div>
                <p class="plan-price">No extra cost</p>
                <p class="plan-description">{{ office.workspace.retention_days }} days of rolling history for the whole workspace. The floor clock and attendance ledger are included.</p>
                <ul class="plan-features">
                    <li v-for="feature in standardFeatures" :key="feature"><i class="pi pi-check" /> {{ feature }}</li>
                </ul>
                <Button
                    v-if="isArchive"
                    label="Return to standard"
                    icon="pi pi-history"
                    severity="secondary"
                    outlined
                    :loading="busy"
                    :disabled="!office.isOwner"
                    @click="chooseStandard"
                />
                <Tag v-else value="You are here" severity="secondary" />
                <p v-if="!office.isOwner" class="fine">Only the workspace owner can change the plan.</p>
            </article>

            <article class="plan-card featured" :class="{ current: isArchive }">
                <div class="plan-ribbon archive" v-if="isArchive">Current plan</div>
                <div class="plan-ribbon" v-else>Most peace of mind</div>
                <div class="plan-glow" />
                <div class="plan-head">
                    <span class="plan-icon"><i class="pi pi-sparkles" /></span>
                    <div>
                        <p class="card-kicker">Forever archive</p>
                        <h2>{{ archivePrice }} <small>/ person / month</small></h2>
                    </div>
                </div>
                <p class="plan-price">{{ monthly }} <small>/ month for {{ office.workspace.seats }} people</small></p>
                <p class="plan-description">Everything stays. Every message, every thread, every checkout post — searchable and safe, forever.</p>
                <ul class="plan-features">
                    <li v-for="feature in archiveFeatures" :key="feature"><i class="pi pi-check" /> {{ feature }}</li>
                </ul>
                <Button
                    v-if="!isArchive"
                    label="Keep history forever"
                    icon="pi pi-sparkles"
                    :loading="busy"
                    :disabled="!office.isOwner"
                    @click="chooseArchive"
                />
                <Tag v-else value="History is safe" severity="success" />
                <p v-if="!office.isOwner" class="fine">Ask the owner to turn this on.</p>
            </article>
        </div>

        <div class="billing-bottom">
            <section class="panel history-panel">
                <header class="card-head">
                    <div><p class="card-kicker">How it works</p><h2>What happens to old history</h2></div>
                </header>
                <ol class="history-steps">
                    <li>
                        <span class="step-number">01</span>
                        <div><strong>Messages live in the workspace</strong><p>Channels, direct messages, and threads are end-to-end encrypted. The server stores ciphertext and cannot read the text.</p></div>
                    </li>
                    <li>
                        <span class="step-number">02</span>
                        <div><strong>Standard keeps a rolling window</strong><p>On the Standard plan, anything older than {{ office.workspace.retention_days }} days is pruned by the nightly history job.</p></div>
                    </li>
                    <li>
                        <span class="step-number">03</span>
                        <div><strong>Archive keeps the whole story</strong><p>Switch to Forever archive and the pruning job skips your workspace entirely. New and old history stays.</p></div>
                    </li>
                </ol>
                <Message severity="info" :closable="false">
                    This records the plan inside SmackChat. Connect a payment provider before charging a real company.
                </Message>
            </section>

            <section class="panel faq-panel">
                <header class="card-head">
                    <div><p class="card-kicker">Questions</p><h2>Good to know</h2></div>
                </header>
                <Accordion :multiple="true">
                    <AccordionPanel value="1">
                        <AccordionHeader>Does archive affect new messages?</AccordionHeader>
                        <AccordionContent>
                            <p>No. New messages are stored the same way. Archive simply stops the nightly job from deleting older ones.</p>
                        </AccordionContent>
                    </AccordionPanel>
                    <AccordionPanel value="2">
                        <AccordionHeader>What happens when someone leaves?</AccordionHeader>
                        <AccordionContent>
                            <p>Their messages stay in place. Their access is removed and they stop counting toward seats after the plan is refreshed.</p>
                        </AccordionContent>
                    </AccordionPanel>
                    <AccordionPanel value="3">
                        <AccordionHeader>Can I go back to Standard?</AccordionHeader>
                        <AccordionContent>
                            <p>Yes. You can return at any time. Once the standard window passes, the nightly job will resume pruning old messages.</p>
                        </AccordionContent>
                    </AccordionPanel>
                    <AccordionPanel value="4">
                        <AccordionHeader>Is the price per seat?</AccordionHeader>
                        <AccordionContent>
                            <p>Archive is {{ archivePrice }} per person per month. The current workspace has {{ office.workspace.seats }} seats, so the total is {{ monthly }} per month.</p>
                        </AccordionContent>
                    </AccordionPanel>
                </Accordion>
            </section>
        </div>
    </div>
</template>
