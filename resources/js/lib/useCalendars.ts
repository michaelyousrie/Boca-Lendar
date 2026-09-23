import { useState } from 'react';
import { router } from '@inertiajs/react';
import type { Connection, CalendarSyncStatus } from '../types';

export default function useCalendars(
    connection: Connection | null,
    sync: Record<string, CalendarSyncStatus>,
) {
    const [hidden, setHidden] = useState<string[]>([]);
    const visible = (connection?.calendars ?? []).filter((calendar) => !hidden.includes(calendar.id));
    const states = visible.map((calendar) => ({
        id: calendar.id,
        name: calendar.name,
        loading: sync[calendar.id]?.refreshing ?? false,
        error: sync[calendar.id]?.error ?? null,
        reconnect: connection!.needs_reconnect,
    }));
    return {
        visibleIds: visible.map((calendar) => calendar.id),
        isVisible: (id: string | null) => !id || !hidden.includes(id),
        loading: states.some((state) => state.loading),
        errors: states.filter((state) => state.error),
        toggle: (id: string) =>
            setHidden((current) =>
                current.includes(id) ? current.filter((value) => value !== id) : [...current, id],
            ),
        refresh: () =>
            router.post(
                '/calendar/events/refresh',
                { calendar_ids: visible.map((calendar) => calendar.id) },
                { preserveScroll: true },
            ),
    };
}
