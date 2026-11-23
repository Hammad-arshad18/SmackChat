import assert from 'node:assert/strict';
import test from 'node:test';
import {
    decideAgentPlan,
    decideAgentSteps,
    fallbackPlan,
    normalizeAgentPlan,
    parseAgentPlan,
    replyBrief,
    resolveOpenTarget,
    routeAgentRequest,
    routeAgentSteps,
    shiftPunchAllowed,
    shiftRefusal,
} from './agent.js';

const office = {
    hasDraft: true,
    hasMessages: true,
    channels: [
        { slug: 'general', label: 'general' },
        { slug: 'design', label: 'design' },
    ],
    people: [
        { id: 2, name: 'Ada Lovelace' },
        { id: 3, name: 'Grace Hopper' },
    ],
};

test('routes obvious commands without a model', () => {
    assert.equal(routeAgentRequest('Summarize this conversation', office).action, 'summarize');
    assert.equal(routeAgentRequest('what is unread?').action, 'unread');
    assert.equal(routeAgentRequest('catch me up', { hasMessages: false }).action, 'unread');
    assert.equal(routeAgentRequest('Am I checked in?').punch, 'status');
    assert.equal(routeAgentRequest('check in').punch, 'check_in');
    assert.equal(routeAgentRequest('break').punch, 'break_in');
    assert.equal(routeAgentRequest('take a break').punch, 'break_in');
    assert.equal(routeAgentRequest('lunch').punch, 'break_in');
    assert.equal(routeAgentRequest('back').punch, 'break_out');
    assert.equal(routeAgentRequest("I'm back").punch, 'break_out');
    assert.equal(routeAgentRequest('punch out').punch, 'check_out');
    assert.equal(routeAgentRequest('punch in').punch, 'check_in');
    assert.equal(routeAgentRequest('unread').action, 'unread');
    assert.equal(routeAgentRequest('check in with Ada about Friday'), null);
    assert.equal(routeAgentRequest('search for Friday demo').query, 'Friday demo');
    assert.equal(routeAgentRequest('look for the demo').query, 'the demo');
    assert.equal(routeAgentRequest('find messages about payroll in this channel').scope, 'channel');
    assert.equal(routeAgentRequest('open #general').target, '#general');
    assert.equal(routeAgentRequest('message Ada Lovelace').intent, 'person');
    assert.equal(routeAgentRequest('make this shorter', office).length, 'shorter');
    assert.equal(routeAgentRequest('make this shorter', { hasDraft: false }).action, 'none');
    assert.equal(routeAgentRequest('draft a reply').action, 'reply');
    assert.equal(routeAgentRequest('write Ask if Friday can move').action, 'draft');
    assert.equal(routeAgentRequest('say I am running late').brief, 'I am running late');
    assert.equal(routeAgentRequest('sum this up', office).action, 'summarize');
    assert.equal(fallbackPlan('lunch').action, 'none');
});

test('parses the last JSON object a small model returns', () => {
    const raw = 'Sure.\n{"action":"search","query":"demo","scope":"workspace"}\nDone.';
    assert.equal(parseAgentPlan(raw).query, 'demo');
    assert.equal(parseAgentPlan('{"action": "none", "say": "No."}').action, 'none');
    assert.equal(parseAgentPlan('not json'), null);
});

test('rejects actions outside the catalog', () => {
    assert.equal(normalizeAgentPlan({ action: 'send', brief: 'hi' }, 'hi', office), null);
    assert.equal(normalizeAgentPlan({ action: 'delete_messages' }, 'delete', office), null);
});

test('turns a reply with no transcript into a draft', () => {
    const plan = normalizeAgentPlan({ action: 'reply', brief: 'Ask for the file' }, 'Ask for the file', { hasMessages: false });
    assert.equal(plan.action, 'draft');
    assert.equal(plan.brief, 'Ask for the file');
});

test('summarize with nothing loaded becomes unread', () => {
    assert.equal(normalizeAgentPlan({ action: 'summarize' }, 'summarize', { hasMessages: false }).action, 'unread');
});

test('rewrite without a draft does not invent one', () => {
    assert.equal(normalizeAgentPlan({ action: 'rewrite' }, 'polish', { hasDraft: false }).action, 'none');
});

test('a question the model cannot parse is not drafted', async () => {
    const plan = await decideAgentPlan('what is the office wifi?', { hasDraft: false, hasMessages: false }, async () => 'I am not sure');
    assert.equal(plan.action, 'none');
    assert.equal(fallbackPlan('ask the team if Friday moves').action, 'draft');
});

test('a request can check in and send a message', async () => {
    const steps = routeAgentSteps('check in and tell the team I am online', { hasMessages: false });
    assert.equal(steps.length, 2);
    assert.equal(steps[0].punch, 'check_in');
    assert.equal(steps[1].action, 'draft');
    assert.equal(steps[1].deliver, true);

    const lunch = routeAgentSteps('lunch and tell the team I stepped out', { hasMessages: false });
    assert.equal(lunch[0].punch, 'break_in');
    assert.equal(lunch[1].action, 'draft');
    assert.match(lunch[1].brief, /stepped out/);

    const search = routeAgentSteps('search for design and payroll');
    assert.equal(search.length, 1);
    assert.equal(search[0].action, 'search');

    let called = false;
    const decided = await decideAgentSteps('check in and post that I am here', office, async () => {
        called = true;
        return '{"action":"none","say":"no"}';
    });
    assert.equal(called, false);
    assert.equal(decided[0].action, 'shift');
    assert.equal(decided[1].deliver, true);
});

test('parses a steps array from the model', () => {
    const parsed = parseAgentPlan('{"steps":[{"action":"shift","punch":"check_in"},{"action":"draft","brief":"I am online"}]}');
    assert.equal(parsed.steps.length, 2);
    assert.equal(parsed.steps[1].brief, 'I am online');
});

test('a routed command never calls the planner', async () => {
    let called = false;
    const plan = await decideAgentPlan('open settings', office, async () => {
        called = true;
        return '{"action":"draft","brief":"no"}';
    });
    assert.equal(called, false);
    assert.equal(plan.action, 'open');
});

test('resolves a unique channel, person, or page', () => {
    assert.deepEqual(resolveOpenTarget('#general', office).type, 'channel');
    assert.equal(resolveOpenTarget('settings', office).id, 'settings');
    assert.equal(resolveOpenTarget('hours', office).id, 'schedule');
    assert.equal(resolveOpenTarget('billing', office).id, 'billing');
    assert.equal(resolveOpenTarget('Ada', office, 'person').name, 'Ada Lovelace');
    assert.equal(resolveOpenTarget('nobody', office), null);
});

test('reply brief keeps only the recent transcript', () => {
    const brief = replyBrief('Ask for the file', [
        { name: 'Ada', text: 'The build failed' },
        { name: 'Grace', text: 'I can look after lunch' },
    ]);
    assert.match(brief, /Ada: The build failed/);
    assert.match(brief, /Ask for the file/);
});

test('clock actions follow the current shift', () => {
    assert.equal(shiftPunchAllowed('check_in', null), true);
    assert.equal(shiftPunchAllowed('check_in', { status: 'open' }), false);
    assert.equal(shiftPunchAllowed('break_out', { status: 'on_break' }), true);
    assert.equal(shiftPunchAllowed('check_out', { status: 'on_break' }), true);
    assert.equal(shiftPunchAllowed('check_out', { status: 'idle' }), false);
    assert.match(shiftRefusal('break_out', { status: 'open' }), /not on a break/);
    assert.match(shiftRefusal('check_in', { status: 'open' }), /already checked in/);
});
