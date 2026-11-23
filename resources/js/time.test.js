import assert from 'node:assert/strict';
import test from 'node:test';
import { averageWorkdayMinutes } from './time.js';

test('average workday leaves out weekends, holidays, and an open shift', () => {
    const minutes = averageWorkdayMinutes([
        { status: 'completed', work_minutes: 540, non_working_day: false },
        { status: 'completed', work_minutes: 510, non_working_day: false },
        { status: 'completed', work_minutes: 90, non_working_day: true },
        { status: 'completed', work_minutes: 120, holiday_name: 'Founders day' },
        { status: 'open', work_minutes: 40, non_working_day: false },
    ]);

    assert.equal(minutes, 525);
});

test('average workday is zero when every finished shift is an off day', () => {
    assert.equal(averageWorkdayMinutes([
        { status: 'completed', counted_minutes: 200, non_working_day: true },
    ]), 0);
});
