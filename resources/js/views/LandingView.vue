<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import BrandMark from '@/components/BrandMark.vue';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import { money } from '@/time';

const auth = useAuthStore();
const ui = useUiStore();
const scrolled = ref(false);
const mobileNav = ref(false);
const openFaq = ref(0);
const activePillar = ref('talk');

const retentionDays = 90;
const archivePriceCents = 700;
const archivePrice = money(archivePriceCents);
const tenSeatPrice = money(archivePriceCents * 10);

const signedIn = computed(() => Boolean(auth.user));
const primaryTo = computed(() => (signedIn.value ? '/app' : '/login?mode=register'));
const primaryLabel = computed(() => (signedIn.value ? 'Open workspace' : 'Get started'));
const secondaryTo = computed(() => (signedIn.value ? '/app' : '/login'));
const secondaryLabel = computed(() => (signedIn.value ? 'Continue' : 'Sign in'));
const themeIcon = computed(() => (ui.isDark ? 'pi-sun' : 'pi-moon'));
const themeLabel = computed(() => (ui.isDark ? 'Switch to light theme' : 'Switch to dark theme'));

const jobs = [
    'Check in for the day',
    'Message #general',
    'Open a private room',
    'Start a thread',
    'Review attendance',
    'Export CSV',
    'Invite the team',
    'Keep history forever',
];

const pillars = [
    { id: 'talk', label: 'Talk' },
    { id: 'time', label: 'Time' },
    { id: 'track', label: 'Track' },
    { id: 'trust', label: 'Trust' },
];

const features = {
    talk: {
        eyebrow: 'Talk',
        title: 'It all starts with a channel',
        body: 'Public rooms for the company, private rooms for sensitive work, and DMs for the short questions. Threads keep #general clean while the long notes stay attached to the right message.',
        points: [
            'Public channels, private rooms, and one-to-one DMs',
            'Threads that reopen from the URL after refresh',
            'Reactions, edit, delete, copy, and @mentions',
            'Rich composer: bold, lists, quotes, code, links, emoji, attachments',
            'Drafts saved locally while you think',
            'Live delivery when Ably is connected; polling fallback otherwise',
            'Search decrypts in the browser — the query never leaves the device',
        ],
    },
    time: {
        eyebrow: 'Time',
        title: 'A floor clock that files the day',
        body: 'Check in, take a break, check out — from Today, beside the conversation. Checkout writes the day’s hours into #timesheets automatically, so attendance is not a second spreadsheet ritual.',
        points: [
            'Punch actions: check in, break in, break out, check out',
            'Live timer and who’s currently on the floor',
            'Checkout posts the day into Timesheets',
            'Breaks stay out of worked hours',
            'Default schedule Mon–Fri, 09:00–17:00 in the workspace timezone',
            'Holidays can repeat yearly',
        ],
    },
    track: {
        eyebrow: 'Track',
        title: 'Attendance leaders can actually read',
        body: 'Owners and admins get monthly heatmaps, overtime past a standard day, schedules, holidays, and CSV export. The record lives next to the conversation that created it.',
        points: [
            'Monthly attendance heatmaps',
            'Overtime past the standard day',
            'Schedules and repeating holidays',
            'CSV export when payroll asks',
            'Today view for unread, shift, and presence',
            'Roles: owner runs billing; admins help run the floor',
        ],
    },
    trust: {
        eyebrow: 'Trust',
        title: 'Private text. Clear roles. Honest limits.',
        body: 'User-written messages are end-to-end encrypted. Server posts like welcome notes and checkout summaries stay readable so Timesheets can work. Attachments are stored so files can be served. No pretending otherwise.',
        points: [
            'End-to-end encryption on what people write',
            'Private search — decrypt and filter on device',
            'Owner, admin, and member roles',
            'Invite-only company workspace',
            'Push alerts and installable PWA',
            'Light, dark, or system theme',
        ],
    },
};

const proofs = [
    { value: `${retentionDays} days`, label: 'Standard history window' },
    { value: 'E2E', label: 'User messages sealed on device' },
    { value: archivePrice, label: 'Per person for forever archive' },
    { value: '1 company', label: 'One workspace per business' },
];

const dayFlow = [
    {
        step: '01',
        title: 'Open the workspace',
        body: 'Register with work email, verify the six-digit code, and SmackChat creates #general and #timesheets for the company.',
    },
    {
        step: '02',
        title: 'Invite people',
        body: 'Send invites from People. New members join public channels automatically and pick up the conversation.',
    },
    {
        step: '03',
        title: 'Talk and punch in',
        body: 'Use channels, threads, and DMs through the day. Check in from Today, take breaks, and check out when you leave.',
    },
    {
        step: '04',
        title: 'Keep the record',
        body: `Stay on Standard for a ${retentionDays}-day rolling window, or turn on Forever archive when history must never age out.`,
    },
];

const detailBlocks = [
    {
        title: 'Composer built for real work',
        body: 'Bold, italic, underline, strike, lists, quotes, code blocks, links, emoji, @mentions, and file attachments. Drafts stay in local storage until you send.',
    },
    {
        title: 'Today is the front door',
        body: 'See your shift, live timer, unread rooms, and who is on the floor — before you open a single channel.',
    },
    {
        title: 'Timesheets without a second app',
        body: 'Checkout writes the day into #timesheets. Attendance heatmaps, overtime, and CSV export stay with owners and admins.',
    },
    {
        title: 'History you can explain',
        body: `Standard keeps ${retentionDays} days. Forever archive is ${archivePrice} per person / month and skips the nightly prune entirely.`,
    },
];

const faqs = [
    {
        q: 'Is Standard really free?',
        a: `Yes. Standard includes chat, the floor clock, attendance, invites, push, and the PWA. Message history rolls for ${retentionDays} days, then older messages are pruned nightly.`,
    },
    {
        q: 'When should we buy Forever archive?',
        a: `When ${retentionDays} days is not enough — audits, long projects, handovers, or a company that never wants old threads to disappear. It is ${archivePrice} per person each month.`,
    },
    {
        q: 'Can we change plans later?',
        a: 'Yes. The workspace owner can turn Forever archive on or return to Standard anytime from Billing. After returning to Standard, the nightly job resumes pruning older messages.',
    },
    {
        q: 'Are messages private?',
        a: 'User-written messages are end-to-end encrypted. Search runs after decrypting in the browser. Checkout and welcome posts stay readable so Timesheets can work. Attachments are server-readable by design.',
    },
    {
        q: 'Who can see attendance?',
        a: 'Everyone uses the floor clock. Owners and admins read the attendance ledger, heatmaps, schedules, holidays, and CSV export.',
    },
    {
        q: 'What happens when I register?',
        a: 'You verify your email with a six-digit code. SmackChat creates your company workspace, adds you as owner, and opens #general and #timesheets so the team can start immediately.',
    },
];

const activeFeature = computed(() => features[activePillar.value]);

function onScroll() {
    scrolled.value = window.scrollY > 12;
}

watch(mobileNav, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});

onMounted(() => {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
});

onUnmounted(() => {
    window.removeEventListener('scroll', onScroll);
    document.body.style.overflow = '';
});

function scrollTo(id) {
    mobileNav.value = false;
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function toggleFaq(index) {
    openFaq.value = openFaq.value === index ? -1 : index;
}

function toggleTheme() {
    ui.setTheme(ui.isDark ? 'light' : 'dark');
}
</script>

<template>
    <div class="mk mk-liquid">
        <svg class="mk-liquid-defs" width="0" height="0" aria-hidden="true" focusable="false">
            <filter id="mk-liquid-bend" x="-20%" y="-20%" width="140%" height="140%" color-interpolation-filters="sRGB">
                <feTurbulence type="fractalNoise" baseFrequency="0.008 0.014" numOctaves="2" seed="7" result="noise" />
                <feGaussianBlur in="noise" stdDeviation="1.6" result="soft" />
                <feDisplacementMap in="SourceGraphic" in2="soft" scale="28" xChannelSelector="R" yChannelSelector="G" />
            </filter>
        </svg>

        <div class="mk-atmosphere" aria-hidden="true">
            <span class="orb orb-a" />
            <span class="orb orb-b" />
            <span class="orb orb-c" />
            <span class="orb orb-d" />
        </div>

        <header class="mk-nav mk-glass" :class="{ on: scrolled, open: mobileNav }">
            <a class="mk-logo" href="#top" @click.prevent="scrollTo('top')">
                <BrandMark size="sm" class="mk-logo-mark" />
                smack<span>.</span>
            </a>

            <nav class="mk-nav-links" aria-label="Primary">
                <button type="button" @click="scrollTo('product')">Product</button>
                <button type="button" @click="scrollTo('how')">How it works</button>
                <button type="button" @click="scrollTo('pricing')">Pricing</button>
            </nav>

            <div class="mk-nav-actions">
                <button
                    type="button"
                    class="mk-theme"
                    :aria-label="themeLabel"
                    :title="themeLabel"
                    @click="toggleTheme"
                >
                    <i :class="['pi', themeIcon]" />
                    <span>{{ ui.isDark ? 'Light' : 'Dark' }}</span>
                </button>
                <RouterLink :to="secondaryTo" class="mk-link">{{ secondaryLabel }}</RouterLink>
                <RouterLink :to="primaryTo" class="mk-btn">{{ primaryLabel }}</RouterLink>
                <button type="button" class="mk-menu" aria-label="Menu" @click="mobileNav = !mobileNav">
                    <i :class="['pi', mobileNav ? 'pi-times' : 'pi-bars']" />
                </button>
            </div>

        </header>

        <Teleport to="body">
            <div v-if="mobileNav" class="mk-mobile-menu">
                <button
                    type="button"
                    class="mk-nav-scrim"
                    aria-label="Close menu"
                    @click="mobileNav = false"
                />
                <div class="mk-nav-drawer" role="dialog" aria-modal="true" aria-label="Menu">
                    <button type="button" @click="scrollTo('product')">Product</button>
                    <button type="button" @click="scrollTo('how')">How it works</button>
                    <button type="button" @click="scrollTo('pricing')">Pricing</button>
                    <button type="button" @click="toggleTheme">{{ themeLabel }}</button>
                    <RouterLink :to="secondaryTo" @click="mobileNav = false">{{ secondaryLabel }}</RouterLink>
                    <RouterLink :to="primaryTo" class="mk-btn" @click="mobileNav = false">{{ primaryLabel }}</RouterLink>
                </div>
            </div>
        </Teleport>

        <main id="top">
            <section class="mk-hero">
                <div class="mk-hero-copy">
                    <p class="mk-kicker dark">Office chat for one company</p>
                    <h1>All your people, hours, and conversations in one workspace</h1>
                    <p class="mk-sub">
                        SmackChat connects the team in encrypted channels — and a floor clock that files attendance into Timesheets when the day ends.
                    </p>
                    <div class="mk-hero-cta">
                        <RouterLink :to="primaryTo" class="mk-btn lg">{{ primaryLabel }}</RouterLink>
                        <button type="button" class="mk-btn ghost lg" @click="scrollTo('pricing')">Find your plan</button>
                    </div>
                    <p class="mk-trust-line">Built for companies that want chat and the clock in the same room</p>
                </div>

                <div class="mk-jobs" aria-hidden="true">
                    <span v-for="job in jobs" :key="job" class="mk-glass-chip">{{ job }}</span>
                </div>

                <div class="mk-hero-ui" aria-hidden="true">
                    <div class="mk-ui">
                        <aside>
                            <div class="mk-ui-brand">smack<span>.</span></div>
                            <div class="mk-ui-item on"><i class="pi pi-home" /> Today</div>
                            <div class="mk-ui-item"><i class="pi pi-stopwatch" /> Attendance</div>
                            <div class="mk-ui-item"><i class="pi pi-users" /> People</div>
                            <p>Channels</p>
                            <div class="mk-ui-item on"><i class="pi pi-hash" /> general</div>
                            <div class="mk-ui-item"><i class="pi pi-hash" /> timesheets</div>
                            <div class="mk-ui-item"><i class="pi pi-lock" /> launch</div>
                        </aside>
                        <div class="mk-ui-main">
                            <header>
                                <span># general</span>
                                <span class="live">3 on the floor</span>
                            </header>
                            <div class="mk-ui-feed">
                                <div class="msg">
                                    <i style="background:#e23d2b">A</i>
                                    <div><strong>Ada</strong><p>Checked in. Standup notes are in the thread.</p></div>
                                </div>
                                <div class="msg">
                                    <i style="background:#0f6e62">G</i>
                                    <div><strong>Grace</strong><p>Frames are ready — posting before lunch.</p></div>
                                </div>
                                <div class="msg mine">
                                    <div><strong>You</strong><p>Perfect. I’ll review after checkout files the hours.</p></div>
                                </div>
                            </div>
                            <div class="mk-ui-shift">
                                <div>
                                    <span>On shift</span>
                                    <strong>4h 12m</strong>
                                </div>
                                <button type="button">Check out</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mk-proof">
                <div v-for="item in proofs" :key="item.label" class="mk-proof-item mk-glass">
                    <strong>{{ item.value }}</strong>
                    <span>{{ item.label }}</span>
                </div>
            </section>

            <section id="product" class="mk-product">
                <div class="mk-section-head">
                    <p class="mk-kicker dark">Product</p>
                    <h2>Reimagine the office day without splitting chat and attendance</h2>
                    <p>
                        SmackChat is built around four jobs every company already does —
                        talk, punch the clock, track the floor, and keep a record you can trust.
                    </p>
                </div>

                <div class="mk-pillars mk-glass-pill" role="tablist" aria-label="Product areas">
                    <button
                        v-for="pillar in pillars"
                        :key="pillar.id"
                        type="button"
                        role="tab"
                        :aria-selected="activePillar === pillar.id"
                        :class="{ on: activePillar === pillar.id }"
                        @click="activePillar = pillar.id"
                    >
                        {{ pillar.label }}
                    </button>
                </div>

                <div class="mk-feature-row">
                    <div class="mk-feature-copy mk-glass">
                        <p class="mk-kicker dark">{{ activeFeature.eyebrow }}</p>
                        <h3>{{ activeFeature.title }}</h3>
                        <p>{{ activeFeature.body }}</p>
                        <ul>
                            <li v-for="point in activeFeature.points" :key="point">{{ point }}</li>
                        </ul>
                        <RouterLink :to="primaryTo" class="mk-text-cta">{{ primaryLabel }} <i class="pi pi-arrow-right" /></RouterLink>
                    </div>

                    <div class="mk-feature-visual mk-glass" :data-pillar="activePillar" aria-hidden="true">
                        <div v-if="activePillar === 'talk'" class="mk-panel chat">
                            <header>Thread · standup</header>
                            <div class="row"><b>A</b><p>Agenda is short — blockers only.</p></div>
                            <div class="row"><b class="g">G</b><p>Blocked on export. Pinged Lin.</p></div>
                            <div class="row mine"><p>I’ll take export after checkout.</p></div>
                        </div>

                        <div v-else-if="activePillar === 'time'" class="mk-panel clock">
                            <p>Today · Asia/Dubai</p>
                            <strong>09:01 — on shift</strong>
                            <div class="meter"><span /></div>
                            <div class="meta"><span>Worked 4h 12m</span><span>Break 32m</span></div>
                            <div class="actions"><span>Break</span><span class="on">Check out</span></div>
                        </div>

                        <div v-else-if="activePillar === 'track'" class="mk-panel track">
                            <p>March attendance</p>
                            <div class="heat">
                                <i v-for="n in 28" :key="n" :style="{ opacity: 0.25 + ((n * 37) % 75) / 100 }" />
                            </div>
                            <div class="meta"><span>Regular 152h</span><span>OT 6h</span></div>
                        </div>

                        <div v-else class="mk-panel trust">
                            <div>
                                <i class="pi pi-lock" />
                                <div>
                                    <strong>Message sealed</strong>
                                    <span>User text is end-to-end encrypted on device</span>
                                </div>
                            </div>
                            <div>
                                <i class="pi pi-eye-slash" />
                                <div>
                                    <strong>Search stays local</strong>
                                    <span>Decrypt and filter in the browser</span>
                                </div>
                            </div>
                            <div>
                                <i class="pi pi-verified" />
                                <div>
                                    <strong>Owner · Admin · Member</strong>
                                    <span>Clear roles for billing and the floor</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mk-details">
                <div class="mk-section-head">
                    <p class="mk-kicker dark">Inside the product</p>
                    <h2>More of what the team actually uses every day</h2>
                    <p>Not a feature dump — the pieces that make SmackChat feel like the office room, not another chat tab.</p>
                </div>
                <div class="mk-detail-grid">
                    <article v-for="item in detailBlocks" :key="item.title" class="mk-glass">
                        <h3>{{ item.title }}</h3>
                        <p>{{ item.body }}</p>
                    </article>
                </div>
            </section>

            <section id="how" class="mk-how">
                <div class="mk-section-head">
                    <p class="mk-kicker dark">How it works</p>
                    <h2>From empty company to a live floor</h2>
                    <p>No long setup. Create the workspace, invite people, and the day can start on the clock.</p>
                </div>
                <ol class="mk-steps">
                    <li v-for="item in dayFlow" :key="item.step" class="mk-glass">
                        <span>{{ item.step }}</span>
                        <strong>{{ item.title }}</strong>
                        <p>{{ item.body }}</p>
                    </li>
                </ol>
            </section>

            <section class="mk-split-band mk-glass dark-glass">
                <div>
                    <p class="mk-kicker">Why companies switch</p>
                    <h2>Stop paying for chat in one tool and hours in another</h2>
                    <p>
                        When checkout posts into Timesheets, the conversation and the record stay together.
                        Leaders read the floor. People keep talking. History either rolls for {{ retentionDays }} days or stays forever.
                    </p>
                </div>
                <ul>
                    <li><strong>One workspace</strong><span>Not a public network — your company room only.</span></li>
                    <li><strong>Hours that write themselves</strong><span>Checkout files the day. Breaks stay honest.</span></li>
                    <li><strong>History on your terms</strong><span>Standard rolls. Archive keeps everything.</span></li>
                </ul>
            </section>

            <section id="pricing" class="mk-pricing">
                <div class="mk-section-head center">
                    <p class="mk-kicker dark">Pricing</p>
                    <h2>Start free to run the office. Upgrade when history must last.</h2>
                    <p>
                        Two clear plans. No seat fees on Standard. Forever archive is {{ archivePrice }} per person / month —
                        {{ tenSeatPrice }} for a team of ten.
                    </p>
                </div>

                <div class="mk-plans">
                    <article class="mk-plan mk-glass">
                        <p class="name">Standard</p>
                        <h3>Free</h3>
                        <p class="lead">For teams that need chat, the floor clock, and attendance today.</p>
                        <ul>
                            <li>{{ retentionDays }}-day message history</li>
                            <li>Encrypted channels, threads, and DMs</li>
                            <li>Rich composer, reactions, and search</li>
                            <li>Floor clock and Timesheets</li>
                            <li>Attendance heatmaps and CSV</li>
                            <li>Invites, roles, push, and PWA</li>
                        </ul>
                        <RouterLink :to="primaryTo" class="mk-btn ghost dark">{{ primaryLabel }}</RouterLink>
                    </article>

                    <article class="mk-plan featured">
                        <p class="tag">Recommended for long memory</p>
                        <p class="name">Forever archive</p>
                        <h3>{{ archivePrice }} <small>/ person / month</small></h3>
                        <p class="lead">For audits, handovers, and companies that never want old threads to disappear.</p>
                        <ul>
                            <li>Everything in Standard</li>
                            <li>Every message kept forever</li>
                            <li>Checkout posts never age out</li>
                            <li>Full attendance history retained</li>
                            <li>Search across the whole record</li>
                            <li>Switch back anytime</li>
                        </ul>
                        <RouterLink :to="primaryTo" class="mk-btn">{{ primaryLabel }}</RouterLink>
                    </article>
                </div>

                <div class="mk-faq mk-glass">
                    <h3>Questions before you start</h3>
                    <article v-for="(item, index) in faqs" :key="item.q" :class="{ open: openFaq === index }">
                        <button type="button" @click="toggleFaq(index)">
                            <span>{{ item.q }}</span>
                            <i :class="['pi', openFaq === index ? 'pi-minus' : 'pi-plus']" />
                        </button>
                        <p v-show="openFaq === index">{{ item.a }}</p>
                    </article>
                </div>
            </section>

            <section class="mk-close mk-glass dark-glass">
                <h2>See what your team can accomplish in SmackChat</h2>
                <p>Open a workspace, invite your people, and put chat and the floor clock in the same place.</p>
                <div class="mk-hero-cta">
                    <RouterLink :to="primaryTo" class="mk-btn lg light">{{ primaryLabel }}</RouterLink>
                    <button type="button" class="mk-btn ghost lg" @click="scrollTo('pricing')">Find your plan</button>
                </div>
            </section>
        </main>

        <footer class="mk-footer mk-glass">
            <div class="mk-footer-brand">
                <a class="mk-logo" href="#top" @click.prevent="scrollTo('top')">
                    <BrandMark size="sm" class="mk-logo-mark" />
                    smack<span>.</span>
                </a>
                <p>Office chat for one company workspace. Encrypted messages, a floor clock, and attendance that files itself.</p>
            </div>
            <div class="mk-footer-cols">
                <div>
                    <p>Product</p>
                    <button type="button" @click="scrollTo('product')">Features</button>
                    <button type="button" @click="scrollTo('how')">How it works</button>
                    <button type="button" @click="scrollTo('pricing')">Pricing</button>
                </div>
                <div>
                    <p>Account</p>
                    <RouterLink :to="secondaryTo">{{ secondaryLabel }}</RouterLink>
                    <RouterLink :to="primaryTo">{{ primaryLabel }}</RouterLink>
                    <button type="button" @click="toggleTheme">{{ ui.isDark ? 'Use light theme' : 'Use dark theme' }}</button>
                </div>
            </div>
        </footer>
    </div>
</template>
