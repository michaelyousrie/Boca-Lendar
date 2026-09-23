export function localDateTime(instant: Date, timezone: string) {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-US', {
            timeZone: timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(instant)
            .map(({ type, value }) => [type, value]),
    );
    return { date: `${parts.year}-${parts.month}-${parts.day}`, start_time: `${parts.hour}:${parts.minute}` };
}

function matchingInstants(date: string, time: string, timezone: string): number[] {
    const wall = Date.parse(`${date}T${time}:00Z`);
    if (!Number.isFinite(wall)) return [];

    const offsets = new Set(
        [-2, 0, 2].map((days) => {
            const instant = wall + days * 86_400_000;
            const local = localDateTime(new Date(instant), timezone);
            return Date.parse(`${local.date}T${local.start_time}:00Z`) - instant;
        }),
    );

    // Round-trip nearby offsets to catch both skipped and repeated clock times.
    return [...offsets]
        .map((offset) => wall - offset)
        .filter((instant) => {
            const local = localDateTime(new Date(instant), timezone);
            return local.date === date && local.start_time === time;
        });
}

export function bookingDefaults(date: string, timezone: string, now: Date) {
    if (date > localDateTime(now, timezone).date) return { date, start_time: '10:00' };

    const quarterHour = 15 * 60_000;
    let instant = (Math.floor(now.getTime() / quarterHour) + 1) * quarterHour;
    while (true) {
        const local = localDateTime(new Date(instant), timezone);
        if (matchingInstants(local.date, local.start_time, timezone).length === 1) return local;
        instant += quarterHour;
    }
}

export function bookingTimeError(
    date: string,
    time: string,
    timezone: string,
    now: Date,
    allDay = false,
): { field: 'date' | 'start_time'; message: string } | null {
    if (date < localDateTime(now, timezone).date) {
        return { field: 'date', message: 'Choose today or a future date.' };
    }
    if (allDay) return null;
    const instants = matchingInstants(date, time, timezone);
    if (instants.length === 0) {
        return {
            field: 'start_time',
            message: 'Choose a valid time. This time may be skipped by a clock change.',
        };
    }
    if (instants.length > 1) {
        return {
            field: 'start_time',
            message: 'This time occurs twice. Choose UTC to specify the exact time.',
        };
    }
    if (instants[0] <= now.getTime()) {
        return { field: 'start_time', message: 'Choose a future time.' };
    }
    return null;
}
