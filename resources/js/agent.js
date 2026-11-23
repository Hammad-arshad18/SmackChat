/**
 * On-device agent for SmackChat.
 *
 * One request becomes up to three actions. Clear commands are routed here so a
 * 135M phone model is not asked to emit tool calls. Anything else is classified
 * by the local model (`planAgentActionLocally`) into the same action list.
 *
 * A draft or reply is sent. A clock punch runs immediately. Message text stays
 * on the device; only the finished message is posted, the same way Send does.
 */

export const AGENT_ACTIONS = ['draft', 'rewrite', 'reply', 'summarize', 'search', 'open', 'unread', 'shift', 'none'];

const ACTION_ALIASES = {
    write: 'draft',
    compose: 'draft',
    recap: 'summarize',
    summary: 'summarize',
    summarise: 'summarize',
    find: 'search',
    lookup: 'search',
    goto: 'open',
    navigate: 'open',
    clock: 'shift',
    punch: 'shift',
    attendance: 'shift',
};

const PAGES = [
    { id: 'today', label: 'Today', names: ['today', 'home', 'lounge', 'dashboard'] },
    { id: 'attendance', label: 'Attendance', names: ['attendance', 'timesheet', 'timesheets'] },
    { id: 'schedule', label: 'Schedule', names: ['schedule', 'hours', 'office hours'] },
    { id: 'people', label: 'People', names: ['people', 'members', 'directory'] },
    { id: 'settings', label: 'Settings', names: ['settings', 'preferences'] },
    { id: 'billing', label: 'Billing', names: ['billing', 'plan', 'plans'] },
];

const PUNCHES = ['status', 'check_in', 'check_out', 'break_in', 'break_out'];
const FOLLOW_KEY = 'smack.agent.followup';

export function agentPlanNeedsModel(plan) {
    if (Array.isArray(plan)) {
        return plan.some((step) => agentPlanNeedsModel(step));
    }

    return !plan || ['draft', 'reply', 'rewrite', 'summarize'].includes(plan.action);
}

export function describeAgentPlan(plan) {
    if (!plan) {
        return 'On this device';
    }

    if (plan.action === 'draft') return 'Drafting a message';
    if (plan.action === 'reply') return 'Drafting a reply';
    if (plan.action === 'rewrite') return plan.mode === 'fix-grammar' ? 'Fixing grammar' : 'Rewriting the draft';
    if (plan.action === 'summarize') return 'Summarizing';
    if (plan.action === 'search') return 'Searching this device';
    if (plan.action === 'open') return 'Opening';
    if (plan.action === 'unread') return 'Checking unread';
    if (plan.action === 'shift') {
        if (plan.punch === 'check_in') return 'Checking in';
        if (plan.punch === 'check_out') return 'Checking out';
        if (plan.punch === 'break_in') return 'Starting a break';
        if (plan.punch === 'break_out') return 'Ending the break';

        return 'Checking your shift';
    }

    return 'On this device';
}

function normalizeRequest(request) {
    return String(request || '')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/^(please\s+)?(can you|could you|would you|ask ai to|ask the ai to)\s+/i, '')
        .replace(/[.?!]+$/g, '')
        .trim();
}

function draftPlan(brief) {
    return {
        action: 'draft',
        brief: brief.trim(),
        tone: 'neutral',
        length: 'short',
        deliver: true,
        source: 'rules',
    };
}

function deliverable(plan) {
    if (!plan) {
        return plan;
    }

    if (plan.action === 'draft' || plan.action === 'reply') {
        return { ...plan, deliver: true };
    }

    return plan;
}

function looksLikeMessage(text) {
    return /^(?:tell|let|post|say|ask|write|draft|reply|notify|ping|send|announce)\b/i.test(text);
}

function rewritePlan(mode, tone, length) {
    return {
        action: 'rewrite',
        mode,
        tone,
        length,
        source: 'rules',
    };
}

/**
 * Match an obvious command without calling the model.
 * @returns {object|null}
 */
export function routeAgentRequest(request, context = {}) {
    const text = normalizeRequest(request);

    if (text.length < 2) {
        return null;
    }

    if (/^(?:what(?:'s| is) unread|show unread|any unread|unread(?: messages)?|missed messages|what did i miss(?: today)?)$/i.test(text)) {
        return { action: 'unread', source: 'rules' };
    }

    if (/^catch me up$/i.test(text)) {
        return context.hasMessages
            ? { action: 'summarize', source: 'rules' }
            : { action: 'unread', source: 'rules' };
    }

    if (/^(?:am i|are we) (?:checked in|clocked in|on the clock|on a break|on break|on lunch)$/i.test(text)
        || /^(?:my )?(?:shift|clock)(?: status)?$/i.test(text)
        || /^status$/i.test(text)
        || /^how long have i been (?:working|on the clock|on break)$/i.test(text)) {
        return { action: 'shift', punch: 'status', source: 'rules' };
    }

    if (/^(?:check|clock|punch|sign)(?:\s*|-)?(?:me\s+)?in$|^(?:check|clock|punch)in$/i.test(text)
        || /^(?:start|begin)(?: my| the)? (?:day|shift|work)$/i.test(text)) {
        return { action: 'shift', punch: 'check_in', source: 'rules' };
    }

    if (/^(?:check|clock|punch)(?:\s*|-)?(?:me\s+)?out$|^(?:check|clock|punch)out$/i.test(text)
        || /^(?:end|finish)(?: my| the)? (?:day|shift)$/i.test(text)
        || /^(?:i(?:'m| am)|im) (?:done|out)(?: for (?:the day|today))?$/i.test(text)
        || /^done for (?:the day|today)$/i.test(text)) {
        return { action: 'shift', punch: 'check_out', source: 'rules' };
    }

    if (/^(?:end|finish|stop)(?: my)? break$/i.test(text)
        || /^back(?: to work| from (?:a )?(?:break|lunch))?$/i.test(text)
        || /^(?:i(?:'m| am)|im) back$/i.test(text)
        || /^resume(?: work)?$/i.test(text)
        || /^off break$/i.test(text)) {
        return { action: 'shift', punch: 'break_out', source: 'rules' };
    }

    if (/^(?:(?:take|start|go on)(?: a)?\s+)?break(?: time)?$/i.test(text)
        || /^(?:going on|on my|on a) break$/i.test(text)
        || /^(?:take |start |going (?:to |on )|on )?lunch(?: break)?$/i.test(text)
        || /^(?:take a |going on a )?coffee break$/i.test(text)
        || /^brb$/i.test(text)) {
        return { action: 'shift', punch: 'break_in', source: 'rules' };
    }

    const search = text.match(/^(?:search|find|look up|look for)\s+(?:for\s+|messages?\s+(?:about|containing|with)\s+)?(.+)$/i);

    if (search) {
        const query = search[1].replace(/\s+in this (?:channel|conversation|chat)$/i, '').trim();
        const here = /in this (?:channel|conversation|chat)/i.test(search[1]);

        if (query.length >= 2) {
            return { action: 'search', query, scope: here ? 'channel' : 'workspace', source: 'rules' };
        }
    }

    const dm = text.match(/^(?:message|dm|chat with)\s+(.+)$/i);

    if (dm) {
        return { action: 'open', target: dm[1].trim(), intent: 'person', source: 'rules' };
    }

    const open = text.match(/^(?:open|go to|switch to|jump to|take me to)\s+(.+)$/i);

    if (open) {
        return { action: 'open', target: open[1].trim(), intent: 'any', source: 'rules' };
    }

    if (/^(?:summarize|summarise|summary|tldr|tl;dr|recap|sum up|sum this up)(?:\s+.*)?$/i.test(text)
        || /^what did we (?:decide|say|agree)/i.test(text)) {
        if (!context.hasMessages) {
            return {
                action: 'none',
                say: 'Open a conversation with messages first.',
                source: 'rules',
            };
        }

        return { action: 'summarize', source: 'rules' };
    }

    if (/^(?:make (?:this|it) )?(?:shorter|tighter|more concise|briefer)$/i.test(text)) {
        return context.hasDraft
            ? rewritePlan('rewrite', 'as-is', 'shorter')
            : { action: 'none', say: 'Write a draft in the composer first.', source: 'rules' };
    }

    if (/^(?:make (?:this|it) )?(?:more )?(?:formal|professional)$/i.test(text)) {
        return context.hasDraft
            ? rewritePlan('rewrite', 'formal', 'as-is')
            : { action: 'none', say: 'Write a draft in the composer first.', source: 'rules' };
    }

    if (/^(?:make (?:this|it) )?(?:more )?casual$|^(?:make (?:this|it) )?friendlier$/i.test(text)) {
        return context.hasDraft
            ? rewritePlan('rewrite', 'casual', 'as-is')
            : { action: 'none', say: 'Write a draft in the composer first.', source: 'rules' };
    }

    if (/^(?:fix|correct) (?:the )?grammar$|^proofread(?: this)?$|^fix spelling$|^grammar$/i.test(text)) {
        return context.hasDraft
            ? rewritePlan('fix-grammar', 'as-is', 'as-is')
            : { action: 'none', say: 'Write a draft in the composer first.', source: 'rules' };
    }

    if (/^(?:rewrite|polish|clean (?:this|it) up)$/i.test(text)) {
        return context.hasDraft
            ? rewritePlan('rewrite', 'as-is', 'as-is')
            : { action: 'none', say: 'Write a draft in the composer first.', source: 'rules' };
    }

    const reply = text.match(/^(?:reply|respond|answer(?: them| this)?|draft a reply)(?:\s*[:,-]\s*|\s+)(.*)$/i);

    if (reply) {
        return {
            action: 'reply',
            brief: reply[1].trim() || 'Write a short reply to the latest messages.',
            tone: 'neutral',
            length: 'short',
            source: 'rules',
        };
    }

    if (/^draft a reply$/i.test(text)) {
        return {
            action: 'reply',
            brief: 'Write a short reply to the latest messages.',
            tone: 'neutral',
            length: 'short',
            source: 'rules',
        };
    }

    const draft = text.match(/^(?:write|draft|compose)\b[:\s-]*(.*)$/i);

    if (draft) {
        const brief = draft[1].trim();

        if (brief.length >= 3) {
            return draftPlan(brief);
        }

        if (context.hasMessages) {
            return {
                action: 'reply',
                brief: 'Write a short reply to the latest messages.',
                tone: 'neutral',
                length: 'short',
                source: 'rules',
            };
        }

        return {
            action: 'none',
            say: 'Describe the message you want drafted.',
            source: 'rules',
        };
    }

    const spoken = text.match(/^(?:tell|let|post|say|notify|ping|announce|send)(?:\s+(?:the\s+)?(?:team|channel|everyone|them|group|office))?\s+(.{3,})$/i);

    if (spoken) {
        return draftPlan(spoken[1]);
    }

    return null;
}

/**
 * One request can be two actions: "check in and tell the team I'm here".
 * The remainder becomes a message only when it is phrased as one, so
 * "search for design and payroll" stays a search.
 * @returns {object[]|null}
 */
export function routeAgentSteps(request, context = {}) {
    const text = normalizeRequest(request);
    const parts = text.match(/^(.+?)\s+(?:and then|then|and)\s+(.+)$/i);

    if (parts) {
        const first = routeAgentRequest(parts[1].trim(), context);

        if (first && !['none', 'draft', 'reply', 'rewrite'].includes(first.action)) {
            const secondContext = {
                ...context,
                hasMessages: context.hasMessages || first.action === 'open',
            };
            let second = routeAgentRequest(parts[2].trim(), secondContext);

            if (!second || second.action === 'none') {
                second = looksLikeMessage(parts[2].trim()) ? draftPlan(parts[2].trim()) : null;
            }

            if (second && second.action !== 'none') {
                return [deliverable(first), deliverable(second)];
            }
        }
    }

    const single = routeAgentRequest(request, context);

    return single ? [deliverable(single)] : null;
}

export function saveAgentFollowup(steps) {
    try {
        sessionStorage.setItem(FOLLOW_KEY, JSON.stringify((steps || []).slice(0, 3)));
    } catch {
        /* storage can be blocked */
    }
}

export function takeAgentFollowup(channelSlug = '') {
    let steps = [];

    try {
        steps = JSON.parse(sessionStorage.getItem(FOLLOW_KEY) || 'null');
    } catch {
        steps = [];
    }

    if (!Array.isArray(steps) || !steps.length) {
        return [];
    }

    const owner = steps[0].channelSlug || '';

    if (owner && channelSlug && owner !== channelSlug) {
        return [];
    }

    try {
        sessionStorage.removeItem(FOLLOW_KEY);
    } catch {
        /* ignore */
    }

    return steps.slice(0, 3);
}

function tryParseObject(slice) {
    try {
        return JSON.parse(slice);
    } catch {
        try {
            return JSON.parse(slice.replace(/'/g, '"'));
        } catch {
            return null;
        }
    }
}

/**
 * Pull the last JSON object a small model managed to emit.
 */
export function parseAgentPlan(text) {
    const source = String(text || '')
        .replace(/```(?:json)?/gi, '')
        .replace(/```/g, '')
        .trim();

    const stepsBlob = source.match(/"steps"\s*:\s*(\[(?:[^\[\]]|\[[^\[\]]*\])*\])/);

    if (stepsBlob) {
        const parsedSteps = tryParseObject(stepsBlob[1]);

        if (Array.isArray(parsedSteps) && parsedSteps.length) {
            return { steps: parsedSteps.slice(0, 3) };
        }
    }

    let last = null;

    for (const match of source.matchAll(/\{[^{}]*\}/g)) {
        const parsed = tryParseObject(match[0]);

        if (parsed && typeof parsed === 'object') {
            last = parsed;
        }
    }

    if (last) {
        return last;
    }

    const action = source.match(/"action"\s*:\s*"([a-z_-]+)"/i) || source.match(/\baction\s*[:=]\s*"?([a-z_-]+)/i);

    if (!action) {
        return null;
    }

    const plan = { action: action[1] };

    for (const key of ['brief', 'query', 'target', 'say', 'tone', 'length', 'mode', 'punch', 'scope']) {
        const found = source.match(new RegExp(`"${key}"\\s*:\\s*"([^"]*)"`, 'i'));

        if (found) {
            plan[key] = found[1].trim();
        }
    }

    return plan;
}

function clip(value, max) {
    const text = String(value || '').replace(/\s+/g, ' ').trim();

    return text.length > max ? text.slice(0, max).trim() : text;
}

/**
 * Force a model object onto the action list. Unknown output is rejected
 * so the caller can fall back, instead of running a guessed tool.
 */
export function normalizeAgentPlan(raw, request, context = {}) {
    if (!raw || typeof raw !== 'object') {
        return null;
    }

    const token = String(raw.action || '').toLowerCase().replace(/[\s-]+/g, '_');
    const action = ACTION_ALIASES[token] || token;

    if (!AGENT_ACTIONS.includes(action)) {
        return null;
    }

    if (action === 'draft') {
        const brief = clip(raw.brief || request, 1000);

        if (brief.length < 3) {
            return { action: 'none', say: 'Describe the message you want drafted.', source: 'model' };
        }

        return { action, brief, tone: toneOf(raw.tone), length: lengthOf(raw.length), deliver: true, source: 'model' };
    }

    if (action === 'reply') {
        if (!context.hasMessages) {
            const brief = clip(raw.brief || request, 1000);

            return brief.length >= 3
                ? { action: 'draft', brief, tone: toneOf(raw.tone), length: 'short', deliver: true, source: 'model' }
                : { action: 'none', say: 'Open a conversation before asking for a reply.', source: 'model' };
        }

        return {
            action,
            brief: clip(raw.brief || 'Write a short reply to the latest messages.', 400),
            tone: toneOf(raw.tone),
            length: 'short',
            deliver: true,
            source: 'model',
        };
    }

    if (action === 'rewrite') {
        if (!context.hasDraft) {
            return { action: 'none', say: 'Write a draft first, or ask for a new message.', source: 'model' };
        }

        const mode = raw.mode === 'fix-grammar' || raw.mode === 'grammar' ? 'fix-grammar' : 'rewrite';

        return {
            action,
            mode,
            tone: mode === 'fix-grammar' ? 'as-is' : toneOf(raw.tone, 'as-is'),
            length: mode === 'fix-grammar' ? 'as-is' : lengthOf(raw.length, 'as-is'),
            source: 'model',
        };
    }

    if (action === 'summarize') {
        if (!context.hasMessages) {
            return { action: 'unread', source: 'model' };
        }

        return { action, source: 'model' };
    }

    if (action === 'search') {
        const query = clip(raw.query || raw.brief || '', 120);

        if (query.length < 2) {
            return { action: 'none', say: 'Say what to search for.', source: 'model' };
        }

        return {
            action,
            query,
            scope: raw.scope === 'channel' ? 'channel' : 'workspace',
            source: 'model',
        };
    }

    if (action === 'open') {
        const target = clip(raw.target || raw.brief || '', 80);

        if (!target) {
            return { action: 'none', say: 'Say which channel, person, or page to open.', source: 'model' };
        }

        return { action, target, intent: 'any', source: 'model' };
    }

    if (action === 'unread') {
        return { action, source: 'model' };
    }

    if (action === 'shift') {
        const punch = PUNCHES.includes(raw.punch) ? raw.punch : 'status';

        return { action, punch, source: 'model' };
    }

    return { action: 'none', say: clip(raw.say || 'I could not match that to an action.', 240), source: 'model' };
}

function toneOf(value, fallback = 'neutral') {
    const tone = String(value || '').toLowerCase();

    if (tone === 'formal' || tone === 'professional' || tone === 'more-formal') return 'formal';
    if (tone === 'casual' || tone === 'more-casual') return 'casual';
    if (tone === 'as-is') return 'as-is';

    return fallback;
}

function lengthOf(value, fallback = 'short') {
    const length = String(value || '').toLowerCase();

    if (length === 'shorter' || length === 'short') return length === 'shorter' ? 'shorter' : 'short';
    if (length === 'as-is') return 'as-is';

    return fallback;
}

export function fallbackPlan(request) {
    const text = String(request || '').trim();

    if (/[?？]$/.test(text) || /^(who|what|when|where|why|how|which|is|are|do|does|did|can|could)\b/i.test(text)) {
        return {
            action: 'none',
            say: 'I could not match that. Try “summarize this”, “search for …”, “open #general”, or describe the message to draft.',
            source: 'fallback',
        };
    }

    if (/^(?:break|lunch|brb|back|resume|unread|status|checkout|checkin|clock ?(?:in|out)|punch(?: (?:in|out))?|done)$/i.test(text)) {
        return {
            action: 'none',
            say: 'I could not match that. Try “check in”, “break”, “back”, “unread”, or describe the message to send.',
            source: 'fallback',
        };
    }

    if (text.length >= 3) {
        return { action: 'draft', brief: text, tone: 'neutral', length: 'short', source: 'fallback' };
    }

    return { action: 'none', say: 'Say a little more about what you want done.', source: 'fallback' };
}

export function applyToneHint(plan, tone) {
    if (!plan || !tone || tone === 'neutral') {
        return plan;
    }

    const next = tone === 'formal' ? 'formal' : 'casual';

    if (plan.action === 'draft' || plan.action === 'reply') {
        return { ...plan, tone: next };
    }

    if (plan.action === 'rewrite' && plan.mode !== 'fix-grammar') {
        return { ...plan, tone: next };
    }

    return plan;
}

/**
 * Route first. The planner runs only when no command matched.
 * Returns up to three actions. `planner` returns the raw model text.
 */
export async function decideAgentSteps(request, context, planner) {
    const routed = routeAgentSteps(request, context);

    if (routed) {
        return routed;
    }

    const raw = await planner(request, context);
    const parsed = parseAgentPlan(raw);

    if (Array.isArray(parsed?.steps)) {
        const steps = parsed.steps
            .map((step) => deliverable(normalizeAgentPlan(step, request, context)))
            .filter((step) => step && step.action !== 'none')
            .slice(0, 3);

        if (steps.length) {
            return steps;
        }
    }

    return [deliverable(normalizeAgentPlan(parsed, request, context) || fallbackPlan(request))];
}

export async function decideAgentPlan(request, context, planner) {
    const steps = await decideAgentSteps(request, context, planner);

    return steps[0];
}

export function agentPlanInstruction(request, context = {}) {
    const channelNames = (context.channels || []).map((channel) => channel.label).filter(Boolean).slice(0, 16);
    const peopleNames = (context.people || []).map((person) => person.name).filter(Boolean).slice(0, 16);
    const catalog = [
        context.channelLabel ? `Open conversation: ${context.channelLabel}` : 'No conversation is open.',
        context.hasDraft ? 'A draft is in the composer.' : 'The composer is empty.',
        context.hasMessages ? 'Recent messages are available on this device.' : 'No messages are loaded.',
        `Shift: ${context.shift || 'unknown'}`,
        channelNames.length ? `Channels: ${channelNames.join(', ')}` : '',
        peopleNames.length ? `People: ${peopleNames.join(', ')}` : '',
    ].filter(Boolean).join('\n');

    const system = [
        'You choose SmackChat actions. Reply with JSON and nothing else.',
        'One action: {"action":"draft","brief":"..."}',
        'Two or three actions: {"steps":[{"action":"shift","punch":"check_in"},{"action":"draft","brief":"..."}]}',
        '{"action":"draft","brief":"what the message should say","tone":"neutral|formal|casual"}',
        '{"action":"rewrite","mode":"rewrite|fix-grammar","tone":"formal|casual|as-is","length":"shorter|as-is"}',
        '{"action":"reply","brief":"what the reply should do"}',
        '{"action":"summarize"}',
        '{"action":"search","query":"words","scope":"workspace|channel"}',
        '{"action":"open","target":"name"}',
        '{"action":"unread"}',
        '{"action":"shift","punch":"status|check_in|check_out|break_in|break_out"}',
        '{"action":"none","say":"one short sentence"}',
        'Use only the channels and people listed. Do not write the message itself.',
    ].join('\n');

    return {
        system,
        user: `${catalog}\nRequest: ${clip(request, 500)}`,
        fromEnd: false,
        maxTokens: 160,
    };
}

export function replyBrief(brief, messages) {
    const lines = (messages || [])
        .map((message) => {
            const body = String(message?.text || '').replace(/\s+/g, ' ').trim();

            if (!body) {
                return '';
            }

            return `${message.name || 'Someone'}: ${body}`;
        })
        .filter(Boolean)
        .slice(-8);

    let transcript = lines.join('\n');

    if (transcript.length > 1200) {
        transcript = transcript.slice(transcript.length - 1200);
    }

    const note = clip(brief || 'Write a short workplace reply.', 400);

    if (!transcript) {
        return note;
    }

    return `Recent messages:\n${transcript}\n\nWrite the next message. ${note}`;
}

function cleanTarget(value) {
    return String(value || '')
        .replace(/^#/, '')
        .replace(/^(?:the|a|an)\s+/i, '')
        .replace(/\s+(?:channel|room|chat|dm)$/i, '')
        .trim();
}

function named(item, keys) {
    return keys.map((key) => String(item?.[key] || '').toLowerCase());
}

function matchNamed(items, needle, keys) {
    const exact = items.find((item) => named(item, keys).includes(needle));

    if (exact) {
        return exact;
    }

    const starts = items.filter((item) => named(item, keys).some((value) => value.startsWith(needle)));

    if (starts.length === 1) {
        return starts[0];
    }

    const includes = items.filter((item) => named(item, keys).some((value) => value.includes(needle)));

    return includes.length === 1 ? includes[0] : null;
}

/**
 * Resolve an open target against pages, channels, and people already loaded.
 * A fuzzy match is used only when it is unique.
 */
export function resolveOpenTarget(target, context = {}, intent = 'any') {
    const name = cleanTarget(target).toLowerCase();

    if (!name) {
        return null;
    }

    if (intent !== 'person') {
        const page = PAGES.find((item) => item.names.includes(name));

        if (page) {
            return { type: 'page', id: page.id, label: page.label };
        }
    }

    const channels = context.channels || [];
    const people = context.people || [];

    if (intent !== 'person') {
        const channel = matchNamed(channels, name, ['slug', 'label']);

        if (channel) {
            return { type: 'channel', slug: channel.slug, label: channel.label };
        }
    }

    if (intent !== 'channel') {
        const person = matchNamed(people, name, ['name']);

        if (person) {
            return { type: 'person', id: person.id, name: person.name };
        }
    }

    return null;
}

export function shiftStatusText(today) {
    const status = today?.status;

    if (!today || status === 'idle') {
        return 'You are not checked in.';
    }

    if (status === 'open') {
        return 'You are checked in.';
    }

    if (status === 'on_break') {
        return 'You are on a break.';
    }

    if (status === 'completed') {
        return today.work_label ? `You already checked out. ${today.work_label} worked.` : 'You already checked out.';
    }

    return 'No shift is open.';
}

export function shiftPunchAllowed(punch, today) {
    const status = today?.status || 'idle';

    if (punch === 'status') return true;
    if (punch === 'check_in') return !today || status === 'idle';
    if (punch === 'break_in') return status === 'open';
    if (punch === 'break_out') return status === 'on_break';
    // Checkout from a break is valid: the server closes the open break in the same punch.
    if (punch === 'check_out') return status === 'open' || status === 'on_break';

    return false;
}

export function shiftRefusal(punch, today) {
    const status = today?.status || 'idle';

    if (punch === 'check_in' && status === 'completed') {
        return 'You already checked out today.';
    }

    if (punch === 'check_in') {
        return 'You are already checked in.';
    }

    if (punch === 'break_in' && status === 'on_break') {
        return 'You are already on a break.';
    }

    if (punch === 'break_out') {
        return 'You are not on a break.';
    }

    if (punch === 'check_out' || punch === 'break_in') {
        return 'Check in before you record a break or checkout.';
    }

    return `${shiftStatusText(today)} That clock action does not fit this shift.`;
}

export function shiftPunchLabel(punch) {
    if (punch === 'check_in') return 'Check in';
    if (punch === 'check_out') return 'Check out';
    if (punch === 'break_in') return 'Start a break';
    if (punch === 'break_out') return 'Back to work';

    return 'Confirm';
}
