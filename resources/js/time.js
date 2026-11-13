export function formatClock(iso) {
    if (!iso) {
        return '';
    }

    return new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(new Date(iso));
}

function asDate(value) {
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return new Date(`${value}T12:00:00`);
    }

    return new Date(value);
}

export function formatDay(iso) {
    if (!iso) {
        return '';
    }

    return new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'short', day: 'numeric' }).format(asDate(iso));
}

export function formatFullDay(value = new Date()) {
    return new Intl.DateTimeFormat(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
    }).format(asDate(value));
}

export function formatMonth(value) {
    if (!value) {
        return '';
    }

    const date = typeof value === 'string' && value.length === 7
        ? new Date(`${value}-01T12:00:00`)
        : new Date(value);

    return new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' }).format(date);
}

export function formatMonthShort(value) {
    const date = new Date(value);

    return new Intl.DateTimeFormat(undefined, { month: 'short' }).format(date);
}

export function formatWeekday(value) {
    return new Intl.DateTimeFormat(undefined, { weekday: 'short' }).format(asDate(value));
}

export function sameDay(a, b) {
    const left = new Date(a);
    const right = new Date(b);

    return left.getFullYear() === right.getFullYear()
        && left.getMonth() === right.getMonth()
        && left.getDate() === right.getDate();
}

export function isToday(value) {
    return sameDay(value, new Date());
}

export function timeAgo(iso) {
    if (!iso) {
        return '';
    }

    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const absolute = Math.abs(seconds);
    const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    if (absolute < 45) return 'just now';
    if (absolute < 3600) return formatter.format(Math.round(seconds / 60), 'minute');
    if (absolute < 86400) return formatter.format(Math.round(seconds / 3600), 'hour');
    if (absolute < 604800) return formatter.format(Math.round(seconds / 86400), 'day');

    return formatDay(iso);
}

export function money(cents) {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format((cents || 0) / 100);
}

export function minutesLabel(minutes) {
    const total = Math.max(0, Math.round(minutes || 0));
    const hours = Math.floor(total / 60);
    const remainder = total % 60;

    if (!hours) {
        return `${remainder}m`;
    }

    return remainder ? `${hours}h ${remainder}m` : `${hours}h`;
}

export function durationClock(seconds) {
    const total = Math.max(0, Math.floor(seconds || 0));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const rest = total % 60;

    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
}

export function isoDate(value = new Date()) {
    const date = new Date(value);
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** Calendar date (YYYY-MM-DD) in a workspace timezone. */
export function isoDateInZone(timeZone, value = new Date()) {
    if (!timeZone) {
        return isoDate(value);
    }

    try {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(new Date(value));
    } catch {
        return isoDate(value);
    }
}

export function currentMonth() {
    return isoDate().slice(0, 7);
}

export function shiftMonth(month, offset) {
    const [year, part] = month.split('-').map(Number);
    const date = new Date(year, part - 1 + offset, 1);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

export function startOfWeek(value = new Date()) {
    const date = new Date(value);
    const day = date.getDay() || 7;
    date.setDate(date.getDate() - day + 1);
    date.setHours(0, 0, 0, 0);

    return date;
}

export function greeting(value = new Date()) {
    const hour = new Date(value).getHours();

    if (hour < 5) return 'Still up';
    if (hour < 12) return 'Good morning';
    if (hour < 17) return 'Good afternoon';

    return 'Good evening';
}

export function shiftTotals(events = [], now = Date.now()) {
    let work = 0;
    let breaks = 0;
    let lastCheckIn = null;
    let breakStarted = null;

    [...events]
        .sort((a, b) => new Date(a.happened_at) - new Date(b.happened_at))
        .forEach((event) => {
            const at = new Date(event.happened_at).getTime();

            if (event.type === 'check_in') {
                lastCheckIn = at;
            }

            if (event.type === 'break_in' && lastCheckIn) {
                work += at - lastCheckIn;
                lastCheckIn = null;
                breakStarted = at;
            }

            if (event.type === 'break_out' && breakStarted) {
                breaks += at - breakStarted;
                breakStarted = null;
                lastCheckIn = at;
            }

            if (event.type === 'check_out' && lastCheckIn) {
                work += at - lastCheckIn;
                lastCheckIn = null;
            }
        });

    if (lastCheckIn) {
        work += now - lastCheckIn;
    }

    if (breakStarted) {
        breaks += now - breakStarted;
    }

    return {
        workSeconds: Math.max(0, Math.floor(work / 1000)),
        breakSeconds: Math.max(0, Math.floor(breaks / 1000)),
    };
}

export function wallClockLabel(hhmm) {
    if (!hhmm) return '';

    const [hour, minute] = hhmm.split(':').map(Number);
    const date = new Date();
    date.setHours(hour || 0, minute || 0, 0, 0);

    return new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(date);
}

export function dateFromTime(value) {
    const [hour, minute] = (value || '09:00').split(':').map(Number);
    const date = new Date();
    date.setHours(hour || 0, minute || 0, 0, 0);

    return date;
}

export function timeFromDate(value) {
    if (!value) return '09:00';

    const date = new Date(value);

    return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}
