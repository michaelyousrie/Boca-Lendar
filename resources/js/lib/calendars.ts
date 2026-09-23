import type { Calendar } from '../types';

export function preferredCalendar(calendars: Calendar[], selectedId: string | null): string {
    return (
        (
            calendars.find((calendar) => calendar.id === selectedId && calendar.writable) ??
            calendars.find((calendar) => calendar.writable)
        )?.id ?? ''
    );
}
