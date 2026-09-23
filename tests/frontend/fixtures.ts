import type { Appointment, Connection } from '../../resources/js/types';

export const appointment: Appointment = {
    revision: 'a'.repeat(64),
    calendar_id: 'work',
    all_day: false,
    writable: true,
    url: null,
    id: 'one',
    title: 'Discovery session',
    customer_name: 'Alex Morgan',
    customer_email: 'alex@example.com',
    starts_at: '2026-10-12T07:00:00Z',
    ends_at: '2026-10-12T08:00:00Z',
    timezone: 'UTC',
    status: 'scheduled',
    sync_status: 'synced',
    sync_error: null,
    calendar_name: 'Work',
    holds_slot: true,
};
export const connection: Connection = {
    email: 'demo@example.com',
    needs_reconnect: false,
    selected_calendar_id: 'work',
    calendars: [
        { id: 'work', name: 'Work', timezone: 'UTC', writable: true },
        { id: 'personal', name: 'Personal', timezone: 'UTC', writable: true },
        { id: 'holiday', name: 'Holidays', timezone: 'UTC', writable: false },
    ],
};
