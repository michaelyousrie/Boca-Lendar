export function calendarDate(date: string): Date {
    return new Date(`${date}T12:00:00Z`);
}

export function addDays(date: string, amount: number): string {
    const result = calendarDate(date);
    result.setUTCDate(result.getUTCDate() + amount);
    return result.toISOString().slice(0, 10);
}

export function monthDays(date: string): (string | null)[] {
    const first = `${date.slice(0, 7)}-01`;
    const weekday = (calendarDate(first).getUTCDay() + 6) % 7;
    const end = calendarDate(first);
    end.setUTCMonth(end.getUTCMonth() + 1, 0);
    return [
        ...Array<null>(weekday).fill(null),
        ...Array.from({ length: end.getUTCDate() }, (_, index) => addDays(first, index)),
    ];
}

export function moveMonth(date: string, amount: number): string {
    const result = calendarDate(`${date.slice(0, 7)}-01`);
    result.setUTCMonth(result.getUTCMonth() + amount);
    return result.toISOString().slice(0, 10);
}

export function dateLabel(date: string, options: Intl.DateTimeFormatOptions): string {
    return calendarDate(date).toLocaleDateString('en-US', { ...options, timeZone: 'UTC' });
}

export function timeLabel(instant: string, timezone: string): string {
    return new Intl.DateTimeFormat('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        timeZone: timezone,
    }).format(new Date(instant));
}

export function durationLabel(start: string, end: string): string {
    const minutes = Math.round((Date.parse(end) - Date.parse(start)) / 60_000);
    const hours = Math.floor(minutes / 60);
    return hours ? `${hours}h${minutes % 60 ? ` ${minutes % 60}m` : ''}` : `${minutes} min`;
}
